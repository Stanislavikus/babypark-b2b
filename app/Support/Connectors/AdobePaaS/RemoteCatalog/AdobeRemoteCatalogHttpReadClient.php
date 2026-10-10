<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use DateTimeImmutable;
use Psr\Http\Message\RequestInterface;
use Throwable;

final class AdobeRemoteCatalogHttpReadClient implements AdobeRemoteCatalogReadClient
{
    private const int MAX_RESPONSE_BODY_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly AdobeRemoteCatalogRequestFactory $requestFactory,
        private readonly ConnectorHttpTransport $transport,
    ) {}

    public function captureBoundary(AdobePaaSRequestContext $context): AdobeRemoteCatalogBoundary
    {
        $payload = $this->send($this->requestFactory->boundary($context));
        $totalCount = $this->totalCount($payload);
        $items = $this->items($payload);

        if ($totalCount === 0) {
            if ($items !== []) {
                throw new AdobeRemoteCatalogReadException('Adobe remote catalogue returned items for an empty boundary.');
            }

            return new AdobeRemoteCatalogBoundary(0, null);
        }

        if (count($items) !== 1) {
            throw new AdobeRemoteCatalogReadException('Adobe remote catalogue boundary must return exactly one maximum-id item.');
        }

        return new AdobeRemoteCatalogBoundary(
            $totalCount,
            $this->entityId($items[0]),
        );
    }

    public function readPage(
        AdobePaaSRequestContext $context,
        int $lastSeenEntityId,
        int $maxEntityId,
        int $pageSize,
    ): AdobeRemoteCatalogPage {
        $payload = $this->send($this->requestFactory->page(
            $context,
            $lastSeenEntityId,
            $maxEntityId,
            $pageSize,
        ));

        return new AdobeRemoteCatalogPage(
            array_map(fn (array $item): AdobeRemoteCatalogItem => $this->mapItem($item), $this->items($payload)),
            $this->totalCount($payload),
        );
    }

    public function countWithinBoundary(
        AdobePaaSRequestContext $context,
        int $maxEntityId,
    ): int {
        return $this->totalCount($this->send($this->requestFactory->boundedCount($context, $maxEntityId)));
    }

    /** @return array<string, mixed> */
    private function send(RequestInterface $request): array
    {
        try {
            $result = $this->transport->send(new ConnectorOutboundRequest(
                $request,
                new ConnectorTransportLimits(
                    connectTimeoutSeconds: 10.0,
                    totalTimeoutSeconds: 60.0,
                    maxResponseBodyBytes: self::MAX_RESPONSE_BODY_BYTES,
                ),
            ));
        } catch (ConnectorTransportException $exception) {
            throw new AdobeRemoteCatalogReadException('Adobe remote catalogue transport failed.', previous: $exception);
        }

        if ($result->statusCode !== 200) {
            throw new AdobeRemoteCatalogReadException(sprintf(
                'Adobe remote catalogue returned unexpected HTTP status %d.',
                $result->statusCode,
            ));
        }

        try {
            $decoded = json_decode($result->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new AdobeRemoteCatalogReadException('Adobe remote catalogue returned invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new AdobeRemoteCatalogReadException('Adobe remote catalogue returned an invalid response object.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private function totalCount(array $payload): int
    {
        $totalCount = $payload['total_count'] ?? null;

        if (! is_int($totalCount) || $totalCount < 0) {
            throw new AdobeRemoteCatalogReadException('Adobe remote catalogue total_count is invalid.');
        }

        return $totalCount;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function items(array $payload): array
    {
        $items = $payload['items'] ?? null;

        if (! is_array($items) || ! array_is_list($items)) {
            throw new AdobeRemoteCatalogReadException('Adobe remote catalogue items are invalid.');
        }

        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new AdobeRemoteCatalogReadException('Adobe remote catalogue item has an invalid shape.');
            }
        }

        /** @var list<array<string, mixed>> $items */
        return $items;
    }

    /** @param array<string, mixed> $item */
    private function mapItem(array $item): AdobeRemoteCatalogItem
    {
        $status = $item['status'] ?? null;

        if ($status !== null && ! is_int($status) && ! is_string($status)) {
            throw new AdobeRemoteCatalogReadException('Adobe remote catalogue Product status is invalid.');
        }

        $customAttributes = $this->customAttributes($item);

        return new AdobeRemoteCatalogItem(
            entityId: $this->entityId($item),
            sku: $this->optionalString($item, 'sku'),
            name: $this->optionalString($item, 'name'),
            typeId: $this->optionalString($item, 'type_id'),
            status: $status === null ? null : (string) $status,
            attributeSetId: $this->optionalPositiveInt($item, 'attribute_set_id'),
            updatedAt: $this->optionalDateTime($item, 'updated_at'),
            thumbnailLocator: $this->thumbnailLocator($item, $customAttributes),
            categoryLinks: $this->categoryLinks($item, $customAttributes),
            customAttributes: $customAttributes,
        );
    }

    /** @param array<string, mixed> $item */
    private function entityId(array $item): int
    {
        $entityId = $item['id'] ?? null;

        if (! is_int($entityId) || $entityId < 1) {
            throw new AdobeRemoteCatalogReadException('Adobe remote catalogue Product id is invalid.');
        }

        return $entityId;
    }

    /** @param array<string, mixed> $item */
    private function optionalPositiveInt(array $item, string $key): ?int
    {
        $value = $item[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            throw new AdobeRemoteCatalogReadException(sprintf('Adobe remote catalogue %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function customAttributes(array $item): array
    {
        $raw = $item['custom_attributes'] ?? [];
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new AdobeRemoteCatalogReadException('Adobe remote catalogue custom_attributes are invalid.');
        }

        $attributes = [];
        foreach ($raw as $entry) {
            if (! is_array($entry) || array_is_list($entry)) {
                throw new AdobeRemoteCatalogReadException('Adobe remote catalogue custom attribute has an invalid shape.');
            }

            $code = $entry['attribute_code'] ?? null;
            if (! is_string($code) || trim($code) === '') {
                throw new AdobeRemoteCatalogReadException('Adobe remote catalogue custom attribute code is invalid.');
            }

            $attributes[$code] = $entry['value'] ?? null;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $customAttributes
     * @return list<AdobeRemoteCatalogCategoryLink>
     */
    private function categoryLinks(array $item, array $customAttributes): array
    {
        $raw = $item['extension_attributes']['category_links'] ?? null;
        $links = [];
        $seen = [];

        if ($raw !== null) {
            if (! is_array($raw) || ! array_is_list($raw)) {
                throw new AdobeRemoteCatalogReadException('Adobe remote catalogue category_links are invalid.');
            }

            foreach ($raw as $entry) {
                if (! is_array($entry) || array_is_list($entry)) {
                    throw new AdobeRemoteCatalogReadException('Adobe remote catalogue category link has an invalid shape.');
                }

                $categoryId = $this->stringIdentifier($entry['category_id'] ?? null, 'category id');
                if (isset($seen[$categoryId])) {
                    continue;
                }

                $seen[$categoryId] = true;
                $position = $entry['position'] ?? null;
                if (is_string($position) && preg_match('/^-?\\d+$/', $position) === 1) {
                    $position = (int) $position;
                }
                if ($position !== null && ! is_int($position)) {
                    throw new AdobeRemoteCatalogReadException('Adobe remote catalogue category position is invalid.');
                }

                $links[] = new AdobeRemoteCatalogCategoryLink($categoryId, $position);
            }

            return $links;
        }

        $fallback = $customAttributes['category_ids'] ?? [];
        if (! is_array($fallback) || ! array_is_list($fallback)) {
            return [];
        }

        foreach ($fallback as $value) {
            $categoryId = $this->stringIdentifier($value, 'category id');
            if (! isset($seen[$categoryId])) {
                $seen[$categoryId] = true;
                $links[] = new AdobeRemoteCatalogCategoryLink($categoryId);
            }
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $customAttributes
     */
    private function thumbnailLocator(array $item, array $customAttributes): ?string
    {
        foreach (['thumbnail', 'small_image', 'image'] as $key) {
            $value = $customAttributes[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '' && $value !== 'no_selection') {
                    return $value;
                }
            }
        }

        $entries = $item['media_gallery_entries'] ?? [];
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new AdobeRemoteCatalogReadException('Adobe remote catalogue media_gallery_entries are invalid.');
        }

        $fallback = null;
        foreach ($entries as $entry) {
            if (! is_array($entry) || array_is_list($entry)) {
                throw new AdobeRemoteCatalogReadException('Adobe remote catalogue media gallery entry has an invalid shape.');
            }

            $disabled = $entry['disabled'] ?? false;
            if ($disabled === true || $disabled === 1 || $disabled === '1') {
                continue;
            }

            $file = $entry['file'] ?? null;
            if (! is_string($file) || trim($file) === '') {
                continue;
            }

            $file = trim($file);
            $types = $entry['types'] ?? [];
            if (is_array($types) && array_is_list($types) && in_array('thumbnail', $types, true)) {
                return $file;
            }

            $fallback ??= $file;
        }

        return $fallback;
    }

    private function stringIdentifier(mixed $value, string $subject): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new AdobeRemoteCatalogReadException(sprintf('Adobe remote catalogue %s is invalid.', $subject));
        }

        $identifier = trim((string) $value);
        if ($identifier === '') {
            throw new AdobeRemoteCatalogReadException(sprintf('Adobe remote catalogue %s is invalid.', $subject));
        }

        return $identifier;
    }

    /** @param array<string, mixed> $item */
    private function optionalString(array $item, string $key): ?string
    {
        $value = $item[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new AdobeRemoteCatalogReadException(sprintf('Adobe remote catalogue %s is invalid.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $item */
    private function optionalDateTime(array $item, string $key): ?DateTimeImmutable
    {
        $value = $this->optionalString($item, $key);

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new AdobeRemoteCatalogReadException(sprintf('Adobe remote catalogue %s is invalid.', $key), previous: $exception);
        }
    }
}
