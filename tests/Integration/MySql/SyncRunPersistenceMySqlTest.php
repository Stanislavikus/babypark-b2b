<?php

namespace Tests\Integration\MySql;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncPreviewOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

class SyncRunPersistenceMySqlTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only integration test.');
        }

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seedFieldDefinitions();
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview],
        ]);
    }

    public function test_revision_v4_rebaseline_matches_runtime_hasher_on_mysql(): void
    {
        $account = $this->createConnectorAccount(null, ['auth_profile' => 'test_sync_support']);
        $configuration = $this->createProductsSyncConfiguration($account);

        $migration = require database_path('migrations/2026_08_17_120000_sync_configuration_revision_v4.php');
        $reflection = new \ReflectionClass($migration);
        $hashMethod = $reflection->getMethod('hashRevisionV4');
        $hashMethod->setAccessible(true);
        $canonicalMethod = $reflection->getMethod('canonicalizePersistedOperations');
        $canonicalMethod->setAccessible(true);
        $connectorConfigMethod = $reflection->getMethod('decodeConnectorExecutionConfiguration');
        $connectorConfigMethod->setAccessible(true);

        $migrationHash = $hashMethod->invoke(
            $migration,
            $canonicalMethod->invoke($migration, ['import']),
            SyncConfigurationOperationalState::Enabled->value,
            [],
            $connectorConfigMethod->invoke($migration, null),
        );

        $stored = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;
        $this->assertSame($migrationHash, $stored);
    }

    public function test_composite_foreign_keys_and_restrict_edges_on_mysql(): void
    {
        $account = $this->createConnectorAccount(null, ['auth_profile' => 'test_sync_support']);
        $configuration = $this->createProductsSyncConfiguration($account);

        $productId = DB::table('products')->insertGetId([
            'workspace_id' => $account->workspace_id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'MYSQL-'.Str::random(6),
            'name' => 'MySQL product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runId = (string) Str::uuid();
        DB::table('sync_runs')->insert([
            'id' => $runId,
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview->value,
            'semantic_operation' => SyncSemanticOperation::Export->value,
            'status' => SyncRunStatus::Queued->value,
            'configuration_snapshot' => json_encode(['selection' => ['mode' => 'all_products']], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sync_run_items')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_run_id' => $runId,
            'product_id' => $productId,
            'outcome' => SyncPreviewOutcome::Ready->value,
            'findings' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('products')->where('id', $productId)->delete();
            $this->fail('Expected product delete to be restricted by sync_run_items FK.');
        } catch (QueryException) {
            // expected
        }

        try {
            SyncRun::withoutWorkspaceScope()->whereKey($runId)->delete();
            $this->fail('Expected sync_run delete to be restricted while items exist.');
        } catch (QueryException) {
            // expected
        }

        try {
            SyncConfiguration::withoutWorkspaceScope()->whereKey($configuration->id)->delete();
            $this->fail('Expected sync_configuration delete to be restricted while runs exist.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(1, SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $runId)->count());
    }
}
