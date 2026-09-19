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

        return new AdobeRemoteCatalogItem(
            entityId: $this->entityId($item),
            sku: $this->optionalString($item, 'sku'),
            name: $this->optionalString($item, 'name'),
            typeId: $this->optionalString($item, 'type_id'),
            status: $status === null ? null : (string) $status,
            updatedAt: $this->optionalDateTime($item, 'updated_at'),
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
