<?php

namespace Tests\Integration\MySql;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Models\SyncRun;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\UpdateSyncConfigurationInput;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class SyncPreviewAdmissionConcurrencyMySqlTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;

    #[Test]
    public function concurrent_preview_admission_serializes_active_run_creation(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Export],
        ]);

        $account = $this->createSyncSupportAccount();
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

        $actor = $this->createStaffUser(UserRole::Manager);
        $membership = $this->makeWorkspaceMembership($account->workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $account->workspace_id,
            'Preview Runner',
            [WorkspacePermissions::RUN_SYNC_PREVIEW],
        );
        $this->assignRoleToMembership($membership, $role);

        $ipcDir = sys_get_temp_dir().'/sync-preview-admission-'.uniqid('', true);
        mkdir($ipcDir);

        $workerScript = base_path('tests/Support/SyncPreviewAdmissionConcurrencyWorker.php');
        $phpBinary = PHP_BINARY;

        $processA = new Process([
            $phpBinary,
            $workerScript,
            'hold-lock',
            $account->workspace_id,
            $account->id,
            $configuration->id,
            $actor->id,
            $ipcDir,
        ], base_path());
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
        ], base_path());
        $processB->setTimeout(120);
        $processB->run();

        file_put_contents($ipcDir.'/release_lock', '1');
        $processA->wait();

        $this->assertStringContainsString('active', strtolower($processB->getOutput().$processB->getErrorOutput()));

        $activeCount = SyncRun::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
            ->count();

        $this->assertLessThanOrEqual(1, $activeCount);
    }

    private function waitForIpcFile(string $path, int $seconds = 30): void
    {
        $deadline = time() + $seconds;

        while (time() < $deadline) {
            if (is_file($path)) {
                return;
            }

            usleep(100_000);
        }

        $this->fail("Timed out waiting for IPC file: {$path}");
    }
}
