<?php

namespace Tests\Integration\MySql;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\SyncRun;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\UpdateSyncConfigurationInput;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class Stage3ALiveSafetyMySqlTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;

    #[Test]
    public function mysql_version_supports_check_constraints_and_stage_3a_migrations_round_trip(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only Stage 3A migration proof.');
        }

        Artisan::call('migrate:fresh');

        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        $this->assertNotEmpty($version);

        $this->assertTrue(Schema::hasColumn('sync_runs', 'recoverable_after'));
        $this->assertTrue(Schema::hasTable('external_record_links'));

        $account = $this->createConnectorAccount();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'MYSQL-PROD',
            'name' => 'MySQL product',
            'is_active' => true,
        ]);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'MYSQL-EXT',
        ]);

        $this->expectException(QueryException::class);
        DB::table('external_record_links')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => null,
            'product_variant_id' => null,
            'external_identifier' => 'INVALID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function concurrent_live_admission_serializes_to_one_active_run(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        Artisan::call('migrate:fresh');
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview],
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Live],
        ]);

        $account = $this->createConnectorAccount(null, ['auth_profile' => 'test_sync_support']);
        $configuration = $this->createProductsSyncConfiguration($account);

        app(SyncConfigurationService::class)->update(
            $account,
            $configuration->id,
            new UpdateSyncConfigurationInput(
                enabledOperations: [SyncSemanticOperation::Export],
                operationalState: SyncConfigurationOperationalState::Enabled,
            ),
        );

        app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 4]),
        );

        SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'completed_at' => now(),
        ]);

        $actor = $this->createStaffUser(UserRole::Manager);
        $membership = $this->makeWorkspaceMembership($account->workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $account->workspace_id,
            'Live Runner',
            [WorkspacePermissions::RUN_SYNC_LIVE],
        );
        $this->assignRoleToMembership($membership, $role);

        $ipcDir = sys_get_temp_dir().'/sync-live-admission-'.uniqid('', true);
        mkdir($ipcDir);

        $workerScript = base_path('tests/Support/SyncLiveAdmissionConcurrencyWorker.php');
        $phpBinary = PHP_BINARY;
        $workerEnv = $this->mysqlWorkerEnvironment();

        $processA = new Process([
            $phpBinary,
            $workerScript,
            'hold-lock',
            $account->workspace_id,
            $account->id,
            $configuration->id,
            $actor->id,
            $ipcDir,
        ], base_path(), $workerEnv);
        $processA->setTimeout(120);
        $processA->start();

        $this->waitForIpcFile($ipcDir.'/lock_acquired');

        $processB = new Process([
            $phpBinary,
            $workerScript,
            'second-admit',
            $account->workspace_id,
            $account->id,
            $configuration->id,
            $actor->id,
            $ipcDir,
        ], base_path(), $workerEnv);
        $processB->setTimeout(120);
        $processB->run();

        file_put_contents($ipcDir.'/release_lock', '1');
        $processA->wait();

        $this->assertSame(0, $processB->getExitCode());

        $outputB = $processB->getOutput().$processB->getErrorOutput();
        $this->assertStringContainsString('ACTIVE_RUN_EXISTS', $outputB);

        $activeCount = SyncRun::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
            ->count();

        $this->assertLessThanOrEqual(1, $activeCount);
    }

    private function waitForIpcFile(string $path): void
    {
        $deadline = time() + 30;
        while (time() < $deadline) {
            if (is_file($path)) {
                return;
            }

            usleep(100_000);
        }

        $this->fail("Timed out waiting for IPC file: {$path}");
    }
}
