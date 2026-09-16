<?php

namespace Tests\Feature\Sync;

use App\Enums\RemoteCatalogScanStatus;
use App\Enums\SyncDataDomain;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\RemoteCatalogCurrentSnapshot;
use App\Models\RemoteCatalogSnapshotItem;
use App\Services\Connectors\Exceptions\RemoteCatalogScanIncompleteException;
use App\Services\Connectors\Exceptions\StaleRemoteCatalogScanException;
use App\Services\Connectors\RemoteCatalogCurrentSnapshotResolver;
use App\Services\Connectors\RemoteCatalogScanService;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\RemoteCatalog\RemoteCatalogItemCandidate;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class RemoteCatalogSnapshotFoundationTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    private RemoteCatalogScanService $scans;

    private RemoteCatalogCurrentSnapshotResolver $current;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);

        $this->scans = app(RemoteCatalogScanService::class);
        $this->current = app(RemoteCatalogCurrentSnapshotResolver::class);
    }

    public function test_successful_snapshot_publication_atomically_replaces_current_projection(): void
    {
        $account = $this->createConnectorAccount();
        $target = $this->target($account);

        $firstScan = $this->scans->begin($account, SyncDataDomain::Products, $target, 2);
        $this->scans->append($firstScan, [
            new RemoteCatalogItemCandidate('101', 'SKU-101', 'First'),
            new RemoteCatalogItemCandidate('102', 'SKU-102', 'Second'),
        ]);
        $first = $this->scans->publish($firstScan);

        $this->assertSame(2, $first->item_count);
        $this->assertNotNull($first->published_at);
        $this->assertSame($first->id, $this->current->resolve($account, SyncDataDomain::Products, $target)?->id);

        $secondScan = $this->scans->begin($account, SyncDataDomain::Products, $target, 1);
        $this->scans->append($secondScan, [new RemoteCatalogItemCandidate('102', 'SKU-102', 'Second updated')]);
        $second = $this->scans->publish($secondScan);

        $this->assertSame($first->id, $second->previous_snapshot_id);
        $this->assertSame($second->id, $this->current->resolve($account, SyncDataDomain::Products, $target)?->id);
        $this->assertSame(['102'], $second->items()->pluck('remote_identifier')->all());
        $this->assertSame(['101', '102'], $first->items()->orderBy('remote_identifier')->pluck('remote_identifier')->all());
    }

    public function test_failed_or_incomplete_scan_never_replaces_previous_current_snapshot(): void
    {
        $account = $this->createConnectorAccount();
        $target = $this->target($account);

        $goodScan = $this->scans->begin($account, SyncDataDomain::Products, $target, 1);
        $this->scans->append($goodScan, [new RemoteCatalogItemCandidate('201', 'SKU-201')]);
        $good = $this->scans->publish($goodScan);

        $failedScan = $this->scans->begin($account, SyncDataDomain::Products, $target, 2);
        $this->scans->append($failedScan, [new RemoteCatalogItemCandidate('202', 'SKU-202')]);

        try {
            $this->scans->publish($failedScan);
            $this->fail('Expected incomplete scan publication to fail.');
        } catch (RemoteCatalogScanIncompleteException) {
            // expected
        }

        $this->assertSame($good->id, $this->current->resolve($account, SyncDataDomain::Products, $target)?->id);
        $this->assertSame(RemoteCatalogScanStatus::Failed, $failedScan->fresh()->status);
        $this->assertSame('incomplete_scan', $failedScan->fresh()->failure_code);
    }

    public function test_target_change_hides_old_projection_but_credential_rotation_does_not(): void
    {
        $account = $this->createConnectorAccount();
        $target = $this->target($account);
        $scan = $this->scans->begin($account, SyncDataDomain::Products, $target, 0);
        $snapshot = $this->scans->publish($scan);

        $account->credentials = ['rotated' => 'encrypted-by-cast'];
        $account->save();
        $account->refresh();

        $this->assertSame($snapshot->id, $this->current->resolve($account, SyncDataDomain::Products, $this->target($account))?->id);

        $account->base_url = 'https://different.example.com';
        $account->save();
        $account->refresh();

        $this->assertNull($this->current->resolve($account, SyncDataDomain::Products, $this->target($account)));
        $this->assertSame(1, RemoteCatalogCurrentSnapshot::withoutWorkspaceScope()->count());
    }

    public function test_stale_older_scan_cannot_supersede_newer_published_snapshot(): void
    {
        $account = $this->createConnectorAccount();
        $target = $this->target($account);

        $older = $this->scans->begin($account, SyncDataDomain::Products, $target, 0);
        $newer = $this->scans->begin($account, SyncDataDomain::Products, $target, 0);
        $newerSnapshot = $this->scans->publish($newer);

        $this->assertSame(1, $older->generation);
        $this->assertSame(2, $newer->generation);

        try {
            $this->scans->publish($older);
            $this->fail('Expected older scan publication to be rejected.');
        } catch (StaleRemoteCatalogScanException) {
            // expected
        }

        $this->assertSame($newerSnapshot->id, $this->current->resolve($account, SyncDataDomain::Products, $target)?->id);
        $this->assertSame(RemoteCatalogScanStatus::Failed, $older->fresh()->status);
        $this->assertSame('stale_scan', $older->fresh()->failure_code);
    }

    public function test_published_snapshot_and_items_are_immutable_and_do_not_create_product_truth(): void
    {
        $account = $this->createConnectorAccount();
        $productCount = Product::withoutWorkspaceScope()->count();
        $linkCount = ExternalRecordLink::withoutWorkspaceScope()->count();
        $scan = $this->scans->begin($account, SyncDataDomain::Products, $this->target($account), 1);
        $this->scans->append($scan, [
            new RemoteCatalogItemCandidate(
                remoteIdentifier: '301',
                sku: 'REMOTE-301',
                name: 'Remote only',
                thumbnailLocator: '/media/catalog/product/r/e/remote.jpg',
                storefrontLocator: ['url_key' => 'remote-only'],
            ),
        ]);
        $snapshot = $this->scans->publish($scan);
        $item = RemoteCatalogSnapshotItem::withoutWorkspaceScope()->where('snapshot_id', $snapshot->id)->sole();

        $this->assertSame($productCount, Product::withoutWorkspaceScope()->count());
        $this->assertSame($linkCount, ExternalRecordLink::withoutWorkspaceScope()->count());
        $this->assertSame('/media/catalog/product/r/e/remote.jpg', $item->thumbnail_locator);
        $this->assertSame(['url_key' => 'remote-only'], $item->storefront_locator);

        $this->expectException(LogicException::class);
        $item->update(['name' => 'Mutated']);
    }

    private function target($account): array
    {
        return app(AdobeConnectorAccountTargetSnapshotResolver::class)
            ->resolve($account)
            ->toEnvelopeArray();
    }
}
