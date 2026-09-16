<?php

namespace Tests\Integration\MySql;

use App\Enums\RemoteCatalogScanStatus;
use App\Enums\SyncDataDomain;
use App\Models\RemoteCatalogCurrentSnapshot;
use App\Models\Workspace;
use App\Services\Connectors\RemoteCatalogScanService;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class RemoteCatalogPersistenceMySqlTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only integration test.');
        }

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    public function test_remote_catalog_foundation_migration_rolls_back_and_reapplies_on_mysql(): void
    {
        $this->assertTrue(Schema::hasTable('remote_catalog_scans'));
        $this->assertTrue(Schema::hasTable('remote_catalog_snapshots'));
        $this->assertTrue(Schema::hasTable('remote_catalog_snapshot_items'));
        $this->assertTrue(Schema::hasTable('remote_catalog_current_snapshots'));

        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_09_16_100000_remote_catalog_foundation.php',
        ]);

        $this->assertFalse(Schema::hasTable('remote_catalog_current_snapshots'));
        $this->assertFalse(Schema::hasTable('remote_catalog_snapshot_items'));
        $this->assertFalse(Schema::hasTable('remote_catalog_snapshots'));
        $this->assertFalse(Schema::hasTable('remote_catalog_scans'));

        Artisan::call('migrate');

        $this->assertTrue(Schema::hasTable('remote_catalog_scans'));
        $this->assertTrue(Schema::hasTable('remote_catalog_current_snapshots'));
    }

    public function test_mysql_rejects_cross_workspace_scan_account_reference(): void
    {
        $account = $this->createConnectorAccount();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other workspace '.Str::random(6),
            'is_default' => false,
        ]);

        $this->expectException(QueryException::class);
        DB::table('remote_catalog_scans')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $otherWorkspace->id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products->value,
            'target_context' => json_encode(['base_url' => 'https://shop.example.com', 'store_code' => 'default']),
            'status' => RemoteCatalogScanStatus::Running->value,
            'received_item_count' => 0,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_mysql_rejects_cross_workspace_snapshot_item_reference(): void
    {
        $account = $this->createConnectorAccount();
        $service = app(RemoteCatalogScanService::class);
        $target = app(AdobeConnectorAccountTargetSnapshotResolver::class)->resolve($account)->toEnvelopeArray();
        $scan = $service->begin($account, SyncDataDomain::Products, $target, 0);
        $snapshot = $scan->snapshot()->withoutGlobalScopes()->firstOrFail();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other workspace '.Str::random(6),
            'is_default' => false,
        ]);

        $this->expectException(QueryException::class);
        DB::table('remote_catalog_snapshot_items')->insert([
            'workspace_id' => $otherWorkspace->id,
            'snapshot_id' => $snapshot->id,
            'remote_identifier' => 'cross-workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_mysql_rejects_current_pointer_to_snapshot_owned_by_another_account(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $otherAccount = $this->createConnectorAccount($workspace);
        $service = app(RemoteCatalogScanService::class);
        $target = app(AdobeConnectorAccountTargetSnapshotResolver::class)->resolve($account)->toEnvelopeArray();
        $scan = $service->begin($account, SyncDataDomain::Products, $target, 0);
        $snapshot = $service->publish($scan);

        RemoteCatalogCurrentSnapshot::withoutWorkspaceScope()->delete();

        $this->expectException(QueryException::class);
        DB::table('remote_catalog_current_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'connector_account_id' => $otherAccount->id,
            'data_domain' => SyncDataDomain::Products->value,
            'snapshot_id' => $snapshot->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_mysql_rejects_snapshot_bound_to_scan_owned_by_another_account(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $otherAccount = $this->createConnectorAccount($workspace);
        $service = app(RemoteCatalogScanService::class);
        $target = app(AdobeConnectorAccountTargetSnapshotResolver::class)->resolve($account)->toEnvelopeArray();
        $scan = $service->begin($account, SyncDataDomain::Products, $target, 0);

        $this->expectException(QueryException::class);
        DB::table('remote_catalog_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'connector_account_id' => $otherAccount->id,
            'data_domain' => SyncDataDomain::Products->value,
            'scan_id' => $scan->id,
            'target_context' => json_encode($target),
            'item_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
