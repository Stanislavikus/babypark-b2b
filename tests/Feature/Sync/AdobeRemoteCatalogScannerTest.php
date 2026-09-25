<?php

namespace Tests\Feature\Sync;

use App\Enums\RemoteCatalogScanStatus;
use App\Enums\SyncDataDomain;
use App\Models\AdobeProductCategoryCatalogueState;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\RemoteCatalogScan;
use App\Models\RemoteCatalogSnapshotItemCategory;
use App\Services\Connectors\AdobeProductCategoryCatalogueReconciler;
use App\Services\Connectors\AdobeRemoteCatalogProjectionService;
use App\Services\Connectors\RemoteCatalogScanService;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogBoundary;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogCategoryCatalogue;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogCategoryCatalogueReader;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogItem;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogPage;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogReadClient;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogScanner;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogTargetChangedException;
use App\Support\Connectors\RemoteCatalog\RemoteCatalogItemCandidate;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
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
                    'items' => [['id' => 3]],
                    'total_count' => 3,
                ]),
                2 => $this->jsonResult([
                    'items' => [[
                        'id' => 6,
                        'parent_id' => 3,
                        'name' => 'Strollers',
                        'is_active' => true,
                        'position' => 1,
                        'level' => 2,
                        'path' => '6',
                    ]],
                    'total_count' => 1,
                ]),
                3 => $this->jsonResult([
                    'items' => [
                        [
                            'id' => 1,
                            'sku' => 'SKU-1',
                            'name' => 'One',
                            'attribute_set_id' => 10,
                            'type_id' => 'simple',
                            'status' => 1,
                            'updated_at' => '2026-09-16 08:00:00',
                            'extension_attributes' => ['category_links' => [['category_id' => '6', 'position' => 1]]],
                            'custom_attributes' => [
                                ['attribute_code' => 'thumbnail', 'value' => '/o/n/one.jpg'],
                                ['attribute_code' => 'manufacturer', 'value' => '991'],
                            ],
                            'media_gallery_entries' => [],
                        ],
                        [
                            'id' => 2,
                            'sku' => 'SKU-2',
                            'name' => 'Two',
                            'attribute_set_id' => 10,
                            'type_id' => 'simple',
                            'status' => 1,
                            'updated_at' => '2026-09-16 08:30:00',
                            'extension_attributes' => ['category_links' => [['category_id' => '6', 'position' => 2]]],
                            'custom_attributes' => [],
                            'media_gallery_entries' => [['file' => '/t/w/two.jpg', 'disabled' => false, 'types' => ['thumbnail']]],
                        ],
                        [
                            'id' => 3,
                            'sku' => 'SKU-3',
                            'name' => 'Three',
                            'attribute_set_id' => 4,
                            'type_id' => 'simple',
                            'status' => 1,
                            'updated_at' => '2026-09-16 09:00:00',
                            'extension_attributes' => ['category_links' => []],
                            'custom_attributes' => [],
                            'media_gallery_entries' => [],
                        ],
                    ],
                    'total_count' => 3,
                ]),
                4 => $this->jsonResult(['items' => [['id' => 1]], 'total_count' => 3]),
                default => throw new \RuntimeException('Unexpected remote catalogue request.'),
            };
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $snapshot = app(AdobeRemoteCatalogScanner::class)->scan($account);

        $this->assertSame(3, $snapshot->item_count);
        $this->assertNotNull($snapshot->published_at);
        $this->assertSame(['1', '2', '3'], $snapshot->items()->orderBy('remote_identifier')->pluck('remote_identifier')->all());
        $this->assertSame(['SKU-1', 'SKU-2', 'SKU-3'], $snapshot->items()->orderBy('remote_identifier')->pluck('sku')->all());
        $first = $snapshot->items()->where('remote_identifier', '1')->firstOrFail();
        $second = $snapshot->items()->where('remote_identifier', '2')->firstOrFail();
        $this->assertSame(10, $first->external_attribute_set_id);
        $this->assertSame('/o/n/one.jpg', $first->thumbnail_locator);
        $this->assertSame('/t/w/two.jpg', $second->thumbnail_locator);
        $this->assertNull($first->provider_brand_field_key);
        $category = RemoteCatalogSnapshotItemCategory::withoutWorkspaceScope()
            ->where('snapshot_item_id', $first->id)
            ->sole();
        $this->assertSame('Strollers', $category->category_path);
        $this->assertSame('6', $category->external_category_id);
        $this->assertSame($productCount, Product::withoutWorkspaceScope()->count());
        $this->assertSame($linkCount, ExternalRecordLink::withoutWorkspaceScope()->count());
        $this->assertSame(RemoteCatalogScanStatus::Succeeded, RemoteCatalogScan::withoutWorkspaceScope()->sole()->status);
        $this->assertSame(4, $transport->sendCount);

        $uris = array_map(
            static fn (ConnectorOutboundRequest $outbound): string => (string) $outbound->request->getUri(),
            $transport->recordedRequests,
        );
        $this->assertTrue(collect($uris)->every(static fn (string $uri): bool => str_contains($uri, 'currentPage%5D=1')));
        $this->assertStringContainsString('/V1/categories/list', $uris[1]);
        $this->assertStringContainsString('condition_type%5D=gt', $uris[2]);
        $this->assertStringContainsString('condition_type%5D=lteq', $uris[2]);
        $this->assertStringContainsString('direction%5D=ASC', $uris[2]);
        $this->assertStringContainsString('pageSize%5D=100', $uris[2]);
    }

    public function test_zero_product_target_still_reconciles_full_category_catalogue(): void
    {
        $account = $this->createConnectorAccount();
        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request, int $count): ConnectorHttpResult {
            return match ($count) {
                1 => $this->jsonResult([
                    'items' => [],
                    'total_count' => 0,
                ]),
                2 => $this->jsonResult([
                    'items' => [
                        ['id' => 1, 'parent_id' => 0, 'name' => 'Root', 'is_active' => true, 'position' => 0, 'level' => 0, 'path' => '1'],
                        ['id' => 3, 'parent_id' => 1, 'name' => 'Store Root', 'is_active' => true, 'position' => 1, 'level' => 1, 'path' => '1/3'],
                        ['id' => 15, 'parent_id' => 3, 'name' => 'Prepared empty category', 'is_active' => true, 'position' => 7, 'level' => 2, 'path' => '1/3/15'],
                    ],
                    'total_count' => 3,
                ]),
                default => throw new \RuntimeException('Unexpected remote catalogue request.'),
            };
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $snapshot = app(AdobeRemoteCatalogScanner::class)->scan($account);

        $this->assertSame(0, $snapshot->item_count);
        $this->assertNotNull($snapshot->published_at);
        $this->assertSame(2, $transport->sendCount);
        $this->assertDatabaseHas('adobe_product_categories', [
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'external_category_id' => '15',
            'parent_external_category_id' => '3',
            'name' => 'Prepared empty category',
            'level' => 2,
            'position' => 7,
            'is_active' => 1,
            'missing_since' => null,
        ]);
        $this->assertDatabaseHas('adobe_product_category_catalogue_states', [
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'category_count' => 3,
        ]);
    }

    public function test_failed_category_read_preserves_last_successful_category_catalogue(): void
    {
        $account = $this->createConnectorAccount();
        $target = app(AdobeConnectorAccountTargetSnapshotResolver::class)->resolve($account);
        $capturedAt = now()->subHour()->toImmutable();

        app(AdobeProductCategoryCatalogueReconciler::class)->reconcile(
            $account,
            $target,
            new AdobeRemoteCatalogCategoryCatalogue($capturedAt, [[
                'external_category_id' => '15',
                'parent_external_category_id' => '3',
                'name' => 'Previously synced',
                'provider_path' => '1/3/15',
                'breadcrumb' => 'Previously synced',
                'level' => 2,
                'position' => 1,
                'is_active' => true,
            ]]),
        );

        $originalSyncedAt = AdobeProductCategoryCatalogueState::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->sole()
            ->last_successful_synced_at;

        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request, int $count): ConnectorHttpResult {
            return $count === 1
                ? $this->jsonResult(['items' => [], 'total_count' => 0])
                : new ConnectorHttpResult(500, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $snapshot = app(AdobeRemoteCatalogScanner::class)->scan($account);

        $this->assertSame(0, $snapshot->item_count);
        $this->assertSame(2, $transport->sendCount);
        $this->assertDatabaseHas('adobe_product_categories', [
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'external_category_id' => '15',
            'name' => 'Previously synced',
            'missing_since' => null,
        ]);
        $state = AdobeProductCategoryCatalogueState::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->sole();
        $this->assertTrue($state->last_successful_synced_at->equalTo($originalSyncedAt));
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

    public function test_response_size_failure_never_replaces_previous_successful_snapshot(): void
    {
        $account = $this->createConnectorAccount();
        $scans = app(RemoteCatalogScanService::class);
        $target = app(AdobeConnectorAccountTargetSnapshotResolver::class)
            ->resolve($account)
            ->toEnvelopeArray();

        $previousScan = $scans->begin($account, SyncDataDomain::Products, $target, 1);
        $scans->append($previousScan, [
            new RemoteCatalogItemCandidate('700', 'OLD-700', 'Previous'),
        ]);
        $previous = $scans->publish($previousScan);

        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request, int $count): ConnectorHttpResult {
            return match ($count) {
                1 => $this->jsonResult([
                    'items' => [['id' => 701]],
                    'total_count' => 1,
                ]),
                2 => $this->jsonResult([
                    'items' => [],
                    'total_count' => 0,
                ]),
                3 => throw new ConnectorTransportException(TransportFailureReason::ResponseSizeExceeded),
                default => throw new \RuntimeException('Unexpected remote catalogue request.'),
            };
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        try {
            app(AdobeRemoteCatalogScanner::class)->scan($account);
            $this->fail('Expected response-size failure.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(
            $previous->id,
            app(AdobeRemoteCatalogProjectionService::class)->currentSnapshot($account)?->id,
        );

        $failed = RemoteCatalogScan::withoutWorkspaceScope()
            ->orderByDesc('generation')
            ->firstOrFail();
        $this->assertSame(RemoteCatalogScanStatus::Failed, $failed->status);
        $this->assertSame('remote_catalog_enumeration_failed', $failed->failure_code);
        $this->assertNull($failed->snapshot->published_at);
        $this->assertSame(3, $transport->sendCount);
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
        $this->app->instance(AdobeRemoteCatalogCategoryCatalogueReader::class, new class implements AdobeRemoteCatalogCategoryCatalogueReader
        {
            public function readCatalogue(AdobePaaSRequestContext $context): AdobeRemoteCatalogCategoryCatalogue
            {
                return new AdobeRemoteCatalogCategoryCatalogue(now()->toImmutable(), []);
            }
        });

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
