<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use JsonException;

final class AdobeRemoteCatalogHttpCategoryDictionaryReader implements AdobeRemoteCatalogCategoryDictionaryReader
{
    private const int PAGE_SIZE = 100;

    private const int MAX_RESPONSE_BODY_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly AdobeRemoteCatalogRequestFactory $requestFactory,
        private readonly ConnectorHttpTransport $transport,
    ) {}

    public function read(AdobePaaSRequestContext $context): array
    {
        $currentPage = 1;
        $expectedTotal = null;
        $records = [];

        do {
            $payload = $this->send($context, $currentPage);
            $totalCount = $this->totalCount($payload);

            if ($expectedTotal === null) {
                $expectedTotal = $totalCount;
            } elseif ($totalCount !== $expectedTotal) {
                throw new AdobeRemoteCatalogReadException('Adobe remote category total_count changed during enumeration.');
            }

            $items = $this->items($payload);
            if ($expectedTotal > count($records) && $items === []) {
                throw new AdobeRemoteCatalogReadException('Adobe remote category enumeration ended before total_count was reached.');
            }

            foreach ($items as $item) {
                $id = $this->positiveInt($item, 'id');
                $key = (string) $id;

                if (isset($records[$key])) {
                    throw new AdobeRemoteCatalogReadException('Adobe remote category enumeration returned a duplicate category id.');
                }

                $records[$key] = [
                    'id' => $id,
                    'parent_id' => $this->nonNegativeInt($item, 'parent_id'),
                    'name' => $this->optionalString($item, 'name'),
                    'level' => $this->nonNegativeInt($item, 'level'),
                    'path' => $this->optionalString($item, 'path'),
                ];
            }

            if (count($records) > $expectedTotal) {
                throw new AdobeRemoteCatalogReadException('Adobe remote category enumeration exceeded total_count.');
            }

            $currentPage++;
        } while (count($records) < $expectedTotal);

        $paths = [];
        foreach ($records as $id => $record) {
            $paths[$id] = $this->breadcrumb($record, $records);
        }

        return $paths;
    }

    /** @return array<string, mixed> */
    private function send(AdobePaaSRequestContext $context, int $currentPage): array
    {
        try {
            $result = $this->transport->send(new ConnectorOutboundRequest(
                $this->requestFactory->categoryPage($context, self::PAGE_SIZE, $currentPage),
                new ConnectorTransportLimits(
                    connectTimeoutSeconds: 10.0,
                    totalTimeoutSeconds: 60.0,
                    maxResponseBodyBytes: self::MAX_RESPONSE_BODY_BYTES,
                ),
            ));
        } catch (ConnectorTransportException $exception) {
            throw new AdobeRemoteCatalogReadException('Adobe remote category transport failed.', previous: $exception);
        }

        if ($result->statusCode !== 200) {
            throw new AdobeRemoteCatalogReadException(sprintf(
                'Adobe remote category list returned unexpected HTTP status %d.',
                $result->statusCode,
            ));
        }

        try {
            $decoded = json_decode($result->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AdobeRemoteCatalogReadException('Adobe remote category list returned invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new AdobeRemoteCatalogReadException('Adobe remote category list returned an invalid response object.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private function totalCount(array $payload): int
    {
        $totalCount = $payload['total_count'] ?? null;

        if (! is_int($totalCount) || $totalCount < 0) {
            throw new AdobeRemoteCatalogReadException('Adobe remote category total_count is invalid.');
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
            throw new AdobeRemoteCatalogReadException('Adobe remote category items are invalid.');
        }

        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new AdobeRemoteCatalogReadException('Adobe remote category item has an invalid shape.');
            }
        }

        /** @var list<array<string, mixed>> $items */
        return $items;
    }

    /** @param array<string, mixed> $item */
    private function positiveInt(array $item, string $key): int
    {
        $value = $item[$key] ?? null;
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            throw new AdobeRemoteCatalogReadException(sprintf('Adobe remote category %s is invalid.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $item */
    private function nonNegativeInt(array $item, string $key): int
    {
        $value = $item[$key] ?? 0;
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 0) {
            throw new AdobeRemoteCatalogReadException(sprintf('Adobe remote category %s is invalid.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $item */
    private function optionalString(array $item, string $key): ?string
    {
        $value = $item[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new AdobeRemoteCatalogReadException(sprintf('Adobe remote category %s is invalid.', $key));
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array{id:int,parent_id:int,name:?string,level:int,path:?string}  $record
     * @param  array<string, array{id:int,parent_id:int,name:?string,level:int,path:?string}>  $records
     */
    private function breadcrumb(array $record, array $records): string
    {
        $ids = [];
        if ($record['path'] !== null) {
            $ids = array_values(array_filter(
                explode('/', $record['path']),
                static fn (string $id): bool => $id !== '' && ctype_digit($id),
            ));
        }

        if ($ids === []) {
            $current = $record;
            $seen = [];

            while (true) {
                $id = (string) $current['id'];
                if (isset($seen[$id])) {
                    throw new AdobeRemoteCatalogReadException('Adobe remote category parent chain contains a cycle.');
                }

                $seen[$id] = true;
                array_unshift($ids, $id);

                if ($current['parent_id'] === 0) {
                    break;
                }

                $parent = $records[(string) $current['parent_id']] ?? null;
                if ($parent === null) {
                    break;
                }

                $current = $parent;
            }
        }

        $labels = [];
        foreach ($ids as $id) {
            $node = $records[(string) $id] ?? null;
            if ($node === null || $node['level'] <= 1 || $node['name'] === null) {
                continue;
            }

            $labels[] = $node['name'];
        }

        return implode(' > ', $labels);
    }
}
