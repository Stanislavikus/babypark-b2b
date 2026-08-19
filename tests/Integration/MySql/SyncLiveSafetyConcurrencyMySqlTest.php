<?php

namespace Tests\Integration\MySql;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\WorkspaceRole;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncLiveAdmissionService;
use App\Services\Sync\SyncPreviewAdmissionService;
use App\Services\Sync\UpdateSyncConfigurationInput;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\SyncLiveAdmissionException;
use App\Support\Sync\Exceptions\SyncPreviewAdmissionException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class SyncLiveSafetyConcurrencyMySqlTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;

    #[Test]
    public function concurrent_live_admission_serializes_active_run_creation(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        Bus::fake();

        [$account, $configuration, $actor] = $this->seedLiveReadyFixture();
        $ipcDir = $this->createIpcDirectory('sync-live-admission');
        $workerEnv = $this->mysqlWorkerEnvironment();
        $workerScript = base_path('tests/Support/SyncLiveSafetyConcurrencyWorker.php');

        $processA = new Process([
            PHP_BINARY,
            $workerScript,
            'concurrent-live-a',
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
            PHP_BINARY,
            $workerScript,
            'concurrent-live-b',
            $account->workspace_id,
            $account->id,
            $configuration->id,
            $actor->id,
            $ipcDir,
        ], base_path(), $workerEnv);
        $processB->setTimeout(120);
        $processB->start();

        $deadline = time() + 30;
        while (time() < $deadline && ! $processB->isRunning()) {
            usleep(50_000);
        }

        $this->assertTrue($processB->isRunning(), 'Second live admission should block while configuration lock is held.');

        file_put_contents($ipcDir.'/release_lock', '1');
        $processA->wait();
        $processB->wait();

        $this->assertSame(0, $processA->getExitCode(), $processA->getErrorOutput());
        $this->assertSame(0, $processB->getExitCode(), $processB->getErrorOutput());
        $this->assertStringContainsString('active', strtolower((string) file_get_contents($ipcDir.'/b_result')));

        $activeCount = SyncRun::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
            ->count();

        $this->assertLessThanOrEqual(1, $activeCount);
    }

    #[Test]
    public function stale_recovery_and_competing_live_admission_serialize(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        Bus::fake();

        [$account, $configuration, $actor] = $this->seedLiveReadyFixture();

        SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Live,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Running,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'started_at' => now()->subHour(),
            'writer_deadline_at' => now()->subMinutes(30),
            'recoverable_after' => now()->subSecond(),
        ]);

        $ipcDir = $this->createIpcDirectory('sync-live-recovery-admit');
        $workerEnv = $this->mysqlWorkerEnvironment();
        $workerScript = base_path('tests/Support/SyncLiveSafetyConcurrencyWorker.php');

        $processA = new Process([
            PHP_BINARY,
            $workerScript,
            'recovery-admit-a',
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
            PHP_BINARY,
            $workerScript,
            'recovery-admit-b',
            $account->workspace_id,
            $account->id,
            $configuration->id,
            $actor->id,
            $ipcDir,
        ], base_path(), $workerEnv);
        $processB->setTimeout(120);
        $processB->start();

        $deadline = time() + 30;
        while (time() < $deadline && ! $processB->isRunning()) {
            usleep(50_000);
        }

        $this->assertTrue($processB->isRunning(), 'Competing admission should block until stale recovery admission completes.');

        file_put_contents($ipcDir.'/release_lock', '1');
        $processA->wait();
        $processB->wait();

        $this->assertSame(0, $processA->getExitCode(), $processA->getErrorOutput());
        $this->assertSame(0, $processB->getExitCode(), $processB->getErrorOutput());
        $this->assertFileExists($ipcDir.'/a_admitted');
        $this->assertStringContainsString('active', strtolower((string) file_get_contents($ipcDir.'/b_result')));

        $activeCount = SyncRun::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
            ->count();

        $this->assertLessThanOrEqual(1, $activeCount);
    }

    #[Test]
    public function rbac_revocation_wins_workspace_lock_before_live_admission(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        Bus::fake();

        [$account, $configuration, $actor, $liveRole] = $this->seedLiveReadyFixture(returnRole: true);
        $this->makeEffectiveHolder($account->workspace, User::factory()->create(), 'Backup Access Holder');

        $ipcDir = $this->createIpcDirectory('sync-live-rbac-revoke-first');
        $workerEnv = $this->mysqlWorkerEnvironment();
        $workerScript = base_path('tests/Support/SyncLiveSafetyConcurrencyWorker.php');

        $processA = new Process([
            PHP_BINARY,
            $workerScript,
            'rbac-revoke-live-a',
            $account->workspace_id,
            $account->id,
            $configuration->id,
            $actor->id,
            $ipcDir,
            $liveRole->id,
        ], base_path(), $workerEnv);
        $processA->setTimeout(120);
        $processA->start();

        $this->waitForIpcFile($ipcDir.'/a_lock_acquired');

        $processB = new Process([
            PHP_BINARY,
            $workerScript,
            'rbac-revoke-live-b',
            $account->workspace_id,
            $account->id,
            $configuration->id,
            $actor->id,
            $ipcDir,
        ], base_path(), $workerEnv);
        $processB->setTimeout(120);
        $processB->start();

        $this->waitForIpcFile($ipcDir.'/b_before_admit');
        touch($ipcDir.'/parent_release_a');

        $processA->wait();
        $processB->wait();

        $this->assertFileExists($ipcDir.'/a_committed');
        $this->assertSame(0, $processA->getExitCode(), $processA->getErrorOutput());
        $this->assertSame(0, $processB->getExitCode(), $processB->getErrorOutput());
        $this->assertStringContainsString('not authorized', strtolower((string) file_get_contents($ipcDir.'/b_result')));

        $this->assertSame(
            0,
            SyncRun::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configuration->id)
                ->where('mode', SyncRunMode::Live)
                ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
                ->count(),
        );
    }

    #[Test]
    public function live_admission_wins_workspace_lock_before_rbac_revocation(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        Bus::fake();

        [$account, $configuration, $actor, $liveRole] = $this->seedLiveReadyFixture(returnRole: true);
        $this->makeEffectiveHolder($account->workspace, User::factory()->create(), 'Backup Access Holder');

        $ipcDir = $this->createIpcDirectory('sync-live-admit-first');
        $workerEnv = $this->mysqlWorkerEnvironment();
        $workerScript = base_path('tests/Support/SyncLiveSafetyConcurrencyWorker.php');

        $processA = new Process([
            PHP_BINARY,
            $workerScript,
            'live-admit-rbac-a',
            $account->workspace_id,
            $account->id,
            $configuration->id,
            $actor->id,
            $ipcDir,
        ], base_path(), $workerEnv);
        $processA->setTimeout(120);
        $processA->start();

        $processB = new Process([
            PHP_BINARY,
            $workerScript,
            'rbac-revoke-live-after-a',
            $account->workspace_id,
            $account->id,
            $configuration->id,
            $actor->id,
            $ipcDir,
            $liveRole->id,
        ], base_path(), $workerEnv);
        $processB->setTimeout(120);
        $processB->start();

        $processA->wait();
        $processB->wait();

        $this->assertSame(0, $processA->getExitCode(), $processA->getErrorOutput());
        $this->assertSame(0, $processB->getExitCode(), $processB->getErrorOutput());
        $this->assertSame('revoked', file_get_contents($ipcDir.'/b_result'));

        $admittedRunId = (string) file_get_contents($ipcDir.'/a_result');
        $this->assertNotSame('', $admittedRunId);

        $this->assertDatabaseHas('sync_runs', [
            'id' => $admittedRunId,
            'mode' => SyncRunMode::Live->value,
            'status' => SyncRunStatus::Queued->value,
        ]);

        $this->assertFalse(
            app(WorkspaceAuthorization::class)->allows(
                $actor,
                $account->workspace,
                WorkspacePermissions::RUN_SYNC_LIVE,
            ),
        );
    }

    #[Test]
    public function active_preview_blocks_live_admission_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only integration proof.');
        }

        [$account, $configuration, $actor] = $this->seedLiveReadyFixture();

        SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Running,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'recoverable_after' => now()->addHour(),
        ]);

        $this->expectException(SyncLiveAdmissionException::class);

        app(SyncLiveAdmissionService::class)->admit($actor, $account, $configuration->id);
    }

    #[Test]
    public function active_live_blocks_preview_admission_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only integration proof.');
        }

        [$account, $configuration, $actor] = $this->seedLiveReadyFixture();

        SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Live,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Running,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'recoverable_after' => now()->addHour(),
        ]);

        $previewActor = $this->createStaffUser(UserRole::Manager);
        $membership = $this->makeWorkspaceMembership($account->workspace, $previewActor);
        $role = $this->createRoleWithPermissions(
            $account->workspace_id,
            'Preview Runner',
            [WorkspacePermissions::RUN_SYNC_PREVIEW],
        );
        $this->assignRoleToMembership($membership, $role);

        $this->expectException(SyncPreviewAdmissionException::class);

        app(SyncPreviewAdmissionService::class)->admit(
            $previewActor,
            $account,
            $configuration->id,
            SyncSemanticOperation::Export,
        );
    }

    /**
     * @return array{0: ConnectorAccount, 1: SyncConfiguration, 2: User, 3?: WorkspaceRole}
     */
    private function seedLiveReadyFixture(bool $returnRole = false): array
    {
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

        $this->publishAuthoritativeSnapshot($account, ['name', 'sku']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productBinding('name')->id,
            'name',
        );

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productVariantBinding('sku')->id,
            'sku',
        );

        $configuration = $configuration->refresh();

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
        $liveRole = $this->createRoleWithPermissions(
            $account->workspace_id,
            'Live Runner',
            [WorkspacePermissions::RUN_SYNC_LIVE],
        );
        $this->assignRoleToMembership($membership, $liveRole);

        if ($returnRole) {
            return [$account, $configuration, $actor, $liveRole];
        }

        return [$account, $configuration, $actor];
    }

    private function createIpcDirectory(string $prefix): string
    {
        $ipcDir = sys_get_temp_dir().'/'.$prefix.'-'.uniqid('', true);
        if (! mkdir($ipcDir) && ! is_dir($ipcDir)) {
            $this->fail('Could not create IPC directory.');
        }

        return $ipcDir;
    }

    /**
     * @return array<string, string>
     */
    private function mysqlWorkerEnvironment(): array
    {
        $connection = DB::connection();

        return array_merge($_ENV, [
            'APP_ENV' => 'testing',
            'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => $connection->getName(),
            'DB_HOST' => (string) $connection->getConfig('host'),
            'DB_PORT' => (string) $connection->getConfig('port'),
            'DB_DATABASE' => (string) $connection->getConfig('database'),
            'DB_USERNAME' => (string) $connection->getConfig('username'),
            'DB_PASSWORD' => (string) $connection->getConfig('password'),
            'DB_SOCKET' => (string) ($connection->getConfig('unix_socket') ?? ''),
            'QUEUE_CONNECTION' => 'sync',
        ]);
    }

    private function waitForIpcFile(string $path, int $seconds = 60): void
    {
        $deadline = time() + $seconds;
        while (! file_exists($path) && time() < $deadline) {
            usleep(50_000);
        }

        if (! file_exists($path)) {
            $this->fail("Timed out waiting for {$path}");
        }
    }
}
