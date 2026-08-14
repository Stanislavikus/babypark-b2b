<?php

namespace Tests\Integration\MySql;

use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\UserRole;
use App\Enums\UserRole;
use App\Models\ConnectorConnectionCheck;
use App\Models\ConnectorDiscoveryRun;
use App\Models\Workspace;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\EnablesConnectorConnectionCheckCapability;
use Tests\Concerns\EnablesConnectorSchemaDiscoveryCapability;
use Tests\TestCase;

class ConnectorDispatchConcurrencyTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use EnablesConnectorConnectionCheckCapability;
    use EnablesConnectorSchemaDiscoveryCapability;

    #[Test]
    public function connection_check_dispatch_fails_after_post_lock_actor_revocation(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        $this->bootstrapConnectorEnvironment();

        $workspace = Workspace::query()->where('is_default', true)->sole();
        $actor = $this->createStaffUserWithConnectorManage(UserRole::Manager);
        $account = $this->createConnectorAccount($workspace);

        $ipcDir = $this->createIpcDirectory('connector-connection-check-revocation');
        $phpBinary = PHP_BINARY;
        $workerScript = base_path('tests/Support/ConnectorDispatchConcurrencyWorker.php');

        $processA = new Process([
            $phpBinary,
            $workerScript,
            'account-lock-a',
            $account->id,
            $ipcDir,
        ], base_path());
        $processA->setTimeout(120);
        $processA->start();

        $this->waitForIpcFile($ipcDir.'/a_lock_acquired');

        $processB = new Process([
            $phpBinary,
            $workerScript,
            'connection-check-b',
            $workspace->id,
            $account->id,
            $actor->id,
            $ipcDir,
        ], base_path());
        $processB->setTimeout(120);
        $processB->start();

        $this->waitForIpcFile($ipcDir.'/b_started');

        $processRevoke = new Process([
            $phpBinary,
            $workerScript,
            'revoke-actor',
            $actor->id,
            $ipcDir,
        ], base_path());
        $processRevoke->setTimeout(60);
        $processRevoke->run();

        touch($ipcDir.'/parent_release_a');

        $processA->wait();
        $processB->wait();

        $this->assertSame(0, $processA->getExitCode());
        $this->assertSame(0, $processB->getExitCode());
        $this->assertSame('unauthorized', trim((string) file_get_contents($ipcDir.'/b_result')));

        $this->assertSame(
            0,
            ConnectorConnectionCheck::withoutWorkspaceScope()
                ->where('connector_account_id', $account->id)
                ->whereIn('status', [
                    ConnectorConnectionCheckStatus::Queued,
                    ConnectorConnectionCheckStatus::Running,
                ])
                ->count(),
        );
    }

    #[Test]
    public function discovery_dispatch_fails_after_post_lock_actor_revocation(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        config(['connectors.discovery.manual_trigger_enabled' => true]);
        $this->bootstrapConnectorEnvironment();

        $workspace = Workspace::query()->where('is_default', true)->sole();
        $actor = $this->createStaffUserWithConnectorManage(UserRole::Manager);
        $account = $this->createConnectorAccount($workspace);

        $ipcDir = $this->createIpcDirectory('connector-discovery-revocation');
        $phpBinary = PHP_BINARY;
        $workerScript = base_path('tests/Support/ConnectorDispatchConcurrencyWorker.php');

        $processA = new Process([
            $phpBinary,
            $workerScript,
            'account-lock-a',
            $account->id,
            $ipcDir,
        ], base_path());
        $processA->setTimeout(120);
        $processA->start();

        $this->waitForIpcFile($ipcDir.'/a_lock_acquired');

        $processB = new Process([
            $phpBinary,
            $workerScript,
            'discovery-b',
            $workspace->id,
            $account->id,
            $actor->id,
            $ipcDir,
        ], base_path());
        $processB->setTimeout(120);
        $processB->start();

        $this->waitForIpcFile($ipcDir.'/b_started');

        $processRevoke = new Process([
            $phpBinary,
            $workerScript,
            'revoke-actor',
            $actor->id,
            $ipcDir,
        ], base_path());
        $processRevoke->setTimeout(60);
        $processRevoke->run();

        touch($ipcDir.'/parent_release_a');

        $processA->wait();
        $processB->wait();

        $this->assertSame(0, $processA->getExitCode());
        $this->assertSame(0, $processB->getExitCode());
        $this->assertSame('unauthorized', trim((string) file_get_contents($ipcDir.'/b_result')));

        $this->assertSame(
            0,
            ConnectorDiscoveryRun::withoutWorkspaceScope()
                ->where('connector_account_id', $account->id)
                ->whereIn('status', [
                    ConnectorDiscoveryRunStatus::Queued,
                    ConnectorDiscoveryRunStatus::Running,
                ])
                ->count(),
        );
    }

    #[Test]
    public function connection_check_dispatch_is_independent_of_workspace_row_mutex(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        $this->bootstrapConnectorEnvironment();

        $workspace = Workspace::query()->where('is_default', true)->sole();
        $actor = $this->createStaffUserWithConnectorManage(UserRole::Manager);
        $account = $this->createConnectorAccount($workspace);

        $ipcDir = $this->createIpcDirectory('connector-workspace-lock-coupling');
        $phpBinary = PHP_BINARY;
        $workerScript = base_path('tests/Support/ConnectorDispatchConcurrencyWorker.php');

        $processWorkspace = new Process([
            $phpBinary,
            $workerScript,
            'workspace-lock-a',
            $workspace->id,
            $ipcDir,
        ], base_path());
        $processWorkspace->setTimeout(120);
        $processWorkspace->start();

        $this->waitForIpcFile($ipcDir.'/workspace_lock_acquired');

        $processDispatch = new Process([
            $phpBinary,
            $workerScript,
            'connection-check-b',
            $workspace->id,
            $account->id,
            $actor->id,
            $ipcDir,
        ], base_path());
        $processDispatch->setTimeout(120);
        $processDispatch->run();

        touch($ipcDir.'/parent_release_workspace');
        $processWorkspace->wait();

        $this->assertSame(0, $processWorkspace->getExitCode());
        $this->assertSame(0, $processDispatch->getExitCode());
        $this->assertSame('dispatched', trim((string) file_get_contents($ipcDir.'/b_result')));

        $this->assertGreaterThan(
            0,
            ConnectorConnectionCheck::withoutWorkspaceScope()
                ->where('connector_account_id', $account->id)
                ->where('status', ConnectorConnectionCheckStatus::Queued)
                ->count(),
        );
    }

    private function bootstrapConnectorEnvironment(): void
    {
        Artisan::call('migrate:fresh');
        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->enableConnectionCheckCapability();
        $this->enableSchemaDiscoveryCapability();
    }

    private function createIpcDirectory(string $prefix): string
    {
        $ipcDir = sys_get_temp_dir().'/'.$prefix.'-'.uniqid('', true);
        if (! mkdir($ipcDir) && ! is_dir($ipcDir)) {
            $this->fail('Could not create IPC directory.');
        }

        return $ipcDir;
    }

    private function waitForIpcFile(string $path, int $seconds = 60): void
    {
        $deadline = time() + $seconds;
        while (! file_exists($path) && time() < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($path, "Timed out waiting for {$path}");
    }
}
