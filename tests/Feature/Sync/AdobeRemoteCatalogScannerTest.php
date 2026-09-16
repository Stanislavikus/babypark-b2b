<?php

namespace Tests\Feature\Sync;

use App\Enums\RemoteCatalogScanStatus;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\RemoteCatalogScan;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogBoundary;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogItem;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogPage;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogReadClient;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogScanner;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogTargetChangedException;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class AdobeRemoteCatalogScannerTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    public function test_scanner_publishes_a_complete_lightweight_snapshot_without_creating_product_truth(): void
    {
        $account = $this->createConnectorAccount();
        $productCount = Product::withoutWorkspaceScope()->count();
        $linkCount = ExternalRecordLink::withoutWorkspaceScope()->count();
        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request, int $count): ConnectorHttpResult {
            return match ($count) {
                1 => $this->jsonResult([
                    'items' => [[
                        'id' => 3,
                        'sku' => 'SKU-3',
                        'name' => 'Three',
                        'type_id' => 'simple',
                        'status' => 1,
                        'updated_at' => '2026-09-16 09:00:00',
                    ]],
                    'total_count' => 3,
                ]),
                2 => $this->jsonResult([
                    'items' => [
                        ['id' => 1, 'sku' => 'SKU-1', 'name' => 'One', 'type_id' => 'simple', 'status' => 1, 'updated_at' => '2026-09-16 08:00:00'],
                        ['id' => 2, 'sku' => 'SKU-2', 'name' => 'Two', 'type_id' => 'simple', 'status' => 1, 'updated_at' => '2026-09-16 08:30:00'],
                        ['id' => 3, 'sku' => 'SKU-3', 'name' => 'Three', 'type_id' => 'simple', 'status' => 1, 'updated_at' => '2026-09-16 09:00:00'],
                    ],
                    'total_count' => 3,
                ]),
                3 => $this->jsonResult(['items' => [['id' => 1]], 'total_count' => 3]),
                default => throw new \RuntimeException('Unexpected remote catalogue request.'),
            };
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $snapshot = app(AdobeRemoteCatalogScanner::class)->scan($account);

        $this->assertSame(3, $snapshot->item_count);
        $this->assertNotNull($snapshot->published_at);
        $this->assertSame(['1', '2', '3'], $snapshot->items()->orderBy('remote_identifier')->pluck('remote_identifier')->all());
        $this->assertSame(['SKU-1', 'SKU-2', 'SKU-3'], $snapshot->items()->orderBy('remote_identifier')->pluck('sku')->all());
        $this->assertSame($productCount, Product::withoutWorkspaceScope()->count());
        $this->assertSame($linkCount, ExternalRecordLink::withoutWorkspaceScope()->count());
        $this->assertSame(RemoteCatalogScanStatus::Succeeded, RemoteCatalogScan::withoutWorkspaceScope()->sole()->status);
        $this->assertSame(3, $transport->sendCount);

        $uris = array_map(
            static fn (ConnectorOutboundRequest $outbound): string => (string) $outbound->request->getUri(),
            $transport->recordedRequests,
        );
        $this->assertTrue(collect($uris)->every(static fn (string $uri): bool => str_contains($uri, 'currentPage%5D=1')));
        $this->assertStringContainsString('condition_type%5D=gt', $uris[1]);
        $this->assertStringContainsString('condition_type%5D=lteq', $uris[1]);
        $this->assertStringContainsString('direction%5D=ASC', $uris[1]);
    }

    public function test_read_failure_marks_candidate_scan_failed_and_never_publishes_it(): void
    {
        $account = $this->createConnectorAccount();
        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request, int $count): ConnectorHttpResult {
            if ($count === 1) {
                return $this->jsonResult([
                    'items' => [['id' => 2, 'sku' => 'SKU-2', 'name' => 'Two', 'type_id' => 'simple', 'status' => 1]],
                    'total_count' => 2,
                ]);
            }

            return new ConnectorHttpResult(500, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        try {
            app(AdobeRemoteCatalogScanner::class)->scan($account);
            $this->fail('Expected remote catalogue scan to fail.');
        } catch (\Throwable) {
            // expected
        }

        $scan = RemoteCatalogScan::withoutWorkspaceScope()->sole();
        $this->assertSame(RemoteCatalogScanStatus::Failed, $scan->status);
        $this->assertSame('remote_catalog_enumeration_failed', $scan->failure_code);
        $this->assertNull($scan->snapshot->published_at);
    }

    public function test_target_change_during_scan_fails_without_publishing_old_target_data(): void
    {
        $account = $this->createConnectorAccount();
        $client = new class($account) implements AdobeRemoteCatalogReadClient
        {
            public function __construct(private readonly ConnectorAccount $account) {}

            public function captureBoundary(AdobePaaSRequestContext $context): AdobeRemoteCatalogBoundary
            {
                return new AdobeRemoteCatalogBoundary(1, 1);
            }

            public function readPage(
                AdobePaaSRequestContext $context,
                int $lastSeenEntityId,
                int $maxEntityId,
                int $pageSize,
            ): AdobeRemoteCatalogPage {
                return new AdobeRemoteCatalogPage([
                    new AdobeRemoteCatalogItem(1, 'SKU-1', 'One', 'simple', '1', null),
                ], 1);
            }

            public function countWithinBoundary(
                AdobePaaSRequestContext $context,
                int $maxEntityId,
            ): int {
                $this->account->forceFill(['base_url' => 'https://changed.example.com'])->save();

                return 1;
            }
        };
        $this->app->instance(AdobeRemoteCatalogReadClient::class, $client);

        try {
            app(AdobeRemoteCatalogScanner::class)->scan($account);
            $this->fail('Expected target change to fail the remote catalogue scan.');
        } catch (AdobeRemoteCatalogTargetChangedException) {
            // expected
        }

        $scan = RemoteCatalogScan::withoutWorkspaceScope()->sole();
        $this->assertSame(RemoteCatalogScanStatus::Failed, $scan->status);
        $this->assertSame('target_changed_during_scan', $scan->failure_code);
        $this->assertNull($scan->snapshot->published_at);
        $this->assertDatabaseCount('remote_catalog_current_snapshots', 0);
    }

    private function jsonResult(array $payload): ConnectorHttpResult
    {
        return new ConnectorHttpResult(200, ['content-type' => ['application/json']], json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
