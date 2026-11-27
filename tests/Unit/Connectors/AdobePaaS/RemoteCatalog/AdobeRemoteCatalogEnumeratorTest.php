<?php

namespace Tests\Unit\Connectors\AdobePaaS\RemoteCatalog;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogBoundary;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogEnumerator;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogItem;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogPage;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogReadClient;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogReadException;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use PHPUnit\Framework\TestCase;

class AdobeRemoteCatalogEnumeratorTest extends TestCase
{
    public function test_keyset_enumeration_crosses_the_old_ten_thousand_and_fifty_page_ceiling(): void
    {
        $total = 10_001;
        $client = new ScriptedAdobeRemoteCatalogReadClient(
            boundary: new AdobeRemoteCatalogBoundary($total, $total),
            page: static function (int $lastSeen, int $max, int $pageSize): AdobeRemoteCatalogPage {
                $items = [];
                $upper = min($max, $lastSeen + $pageSize);

                for ($id = $lastSeen + 1; $id <= $upper; $id++) {
                    $items[] = new AdobeRemoteCatalogItem($id, 'SKU-'.$id, 'Product '.$id, 'simple', '1', null);
                }

                return new AdobeRemoteCatalogPage($items, $max - $lastSeen);
            },
            boundedCount: $total,
        );
        $enumerator = new AdobeRemoteCatalogEnumerator($client);
        $pageCount = 0;
        $consumed = 0;
        $maxChunk = 0;

        $boundary = $enumerator->captureBoundary($this->context());
        $received = $enumerator->enumerateWithinBoundary(
            $this->context(),
            $boundary,
            static function (array $items) use (&$pageCount, &$consumed, &$maxChunk): void {
                $pageCount++;
                $consumed += count($items);
                $maxChunk = max($maxChunk, count($items));
            },
        );

        $this->assertSame($total, $received);
        $this->assertSame($total, $consumed);
        $this->assertSame(101, $pageCount);
        $this->assertSame(101, $client->pageCalls);
        $this->assertSame(100, $maxChunk);
        $this->assertSame(1, $client->boundedCountCalls);
    }

    public function test_unexpected_empty_page_fails_instead_of_publishing_a_partial_enumeration(): void
    {
        $client = new ScriptedAdobeRemoteCatalogReadClient(
            boundary: new AdobeRemoteCatalogBoundary(2, 2),
            page: static fn (): AdobeRemoteCatalogPage => new AdobeRemoteCatalogPage([], 2),
            boundedCount: 2,
        );

        $this->expectException(AdobeRemoteCatalogReadException::class);
        $this->expectExceptionMessage('stopped after 0 of 2');

        (new AdobeRemoteCatalogEnumerator($client))->enumerateWithinBoundary(
            $this->context(),
            $client->captureBoundary($this->context()),
            static function (): void {},
        );
    }

    public function test_duplicate_or_out_of_order_entity_id_fails_closed(): void
    {
        $client = new ScriptedAdobeRemoteCatalogReadClient(
            boundary: new AdobeRemoteCatalogBoundary(2, 2),
            page: static fn (): AdobeRemoteCatalogPage => new AdobeRemoteCatalogPage([
                new AdobeRemoteCatalogItem(1, 'A', null, null, null, null),
                new AdobeRemoteCatalogItem(1, 'B', null, null, null, null),
            ], 2),
            boundedCount: 2,
        );

        $this->expectException(AdobeRemoteCatalogReadException::class);
        $this->expectExceptionMessage('not strictly increasing');

        (new AdobeRemoteCatalogEnumerator($client))->enumerateWithinBoundary(
            $this->context(),
            $client->captureBoundary($this->context()),
            static function (): void {},
        );
    }

    public function test_final_bounded_count_change_fails_closed(): void
    {
        $client = new ScriptedAdobeRemoteCatalogReadClient(
            boundary: new AdobeRemoteCatalogBoundary(2, 2),
            page: static fn (): AdobeRemoteCatalogPage => new AdobeRemoteCatalogPage([
                new AdobeRemoteCatalogItem(1, 'A', null, null, null, null),
                new AdobeRemoteCatalogItem(2, 'B', null, null, null, null),
            ], 2),
            boundedCount: 1,
        );

        $this->expectException(AdobeRemoteCatalogReadException::class);
        $this->expectExceptionMessage('bounded count changed from 2 to 1');

        (new AdobeRemoteCatalogEnumerator($client))->enumerateWithinBoundary(
            $this->context(),
            $client->captureBoundary($this->context()),
            static function (): void {},
        );
    }

    private function context(): AdobePaaSRequestContext
    {
        return new AdobePaaSRequestContext(
            'https://shop.example.com',
            'default',
            new OAuth1Credentials('ck', 'cs', 'at', 'ts'),
        );
    }
}

final class ScriptedAdobeRemoteCatalogReadClient implements AdobeRemoteCatalogReadClient
{
    public int $pageCalls = 0;

    public int $boundedCountCalls = 0;

    public function __construct(
        private readonly AdobeRemoteCatalogBoundary $boundary,
        private readonly \Closure $page,
        private readonly int $boundedCount,
    ) {}

    public function captureBoundary(AdobePaaSRequestContext $context): AdobeRemoteCatalogBoundary
    {
        return $this->boundary;
    }

    public function readPage(
        AdobePaaSRequestContext $context,
        int $lastSeenEntityId,
        int $maxEntityId,
        int $pageSize,
    ): AdobeRemoteCatalogPage {
        $this->pageCalls++;

        return ($this->page)($lastSeenEntityId, $maxEntityId, $pageSize);
    }

    public function countWithinBoundary(AdobePaaSRequestContext $context, int $maxEntityId): int
    {
        $this->boundedCountCalls++;

        return $this->boundedCount;
    }
}
