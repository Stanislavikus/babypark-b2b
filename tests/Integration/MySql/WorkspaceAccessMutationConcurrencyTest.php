<?php

namespace Tests\Integration\MySql;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAccessEffectiveHolderQuery;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class WorkspaceAccessMutationConcurrencyTest extends TestCase
{
    use InteractsWithWorkspaceRbac;

    #[Test]
    public function concurrent_coordinator_mutations_serialize_on_workspace_row_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        Artisan::call('migrate:fresh');

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $workspace = Workspace::query()->where('is_default', true)->sole();
        $holderA = $this->makeEffectiveHolder($workspace, null, 'Concurrent Holder A');
        $holderB = $this->makeEffectiveHolder($workspace, null, 'Concurrent Holder B');

        $ipcDir = sys_get_temp_dir().'/workspace-access-concurrency-'.uniqid('', true);
        if (! mkdir($ipcDir) && ! is_dir($ipcDir)) {
            $this->fail('Could not create IPC directory.');
        }

        $phpBinary = PHP_BINARY;
        $workerScript = base_path('tests/Support/WorkspaceAccessMutationConcurrencyWorker.php');

        $processA = new Process([
            $phpBinary,
            $workerScript,
            'transaction-a',
            $workspace->id,
            $holderA->id,
            $ipcDir,
        ], base_path());
        $processA->setTimeout(120);
        $processA->start();

        $deadline = time() + 60;
        while (! file_exists($ipcDir.'/a_lock_acquired') && time() < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($ipcDir.'/a_lock_acquired', 'Process A did not acquire coordinator workspace lock.');

        $processB = new Process([
            $phpBinary,
            $workerScript,
            'transaction-b',
            $workspace->id,
            $holderB->id,
            $ipcDir,
        ], base_path());
        $processB->setTimeout(120);
        $processB->start();

        $deadline = time() + 60;
        while (! file_exists($ipcDir.'/b_before_coordinator') && time() < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($ipcDir.'/b_before_coordinator', 'Process B did not signal before coordinator.');
        $this->assertFileDoesNotExist($ipcDir.'/b_mutator_executed', 'Process B mutator executed while A held the lock.');

        touch($ipcDir.'/parent_release_a');

        $processA->wait();
        $processB->wait();

        $this->assertFileExists($ipcDir.'/a_committed', 'Process A did not commit successfully.');
        $this->assertFileDoesNotExist($ipcDir.'/a_failed');
        $this->assertSame(0, $processA->getExitCode(), $processA->getErrorOutput());

        $this->assertFileExists($ipcDir.'/b_mutator_executed', 'Process B mutator did not execute after A released.');
        $this->assertSame('lockout', file_get_contents($ipcDir.'/b_result'));
        $this->assertSame(0, $processB->getExitCode(), $processB->getErrorOutput());

        $this->assertDatabaseHas('workspace_users', [
            'id' => $holderA->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('workspace_users', [
            'id' => $holderB->id,
            'is_active' => true,
        ]);

        $effectiveHolderCount = app(WorkspaceAccessEffectiveHolderQuery::class)
            ->countEffectiveHolders($workspace->id);

        $this->assertSame(1, $effectiveHolderCount);
    }

    #[Test]
    public function revoked_actor_access_mutation_fails_after_workspace_lock_is_released(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        Artisan::call('migrate:fresh');
        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create(['is_active' => true]);
        $this->makeEffectiveHolder($workspace, $actor, 'Actor A');
        $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Backup Holder B');

        $targetMembership = $this->makeWorkspaceMembership($workspace);
        $targetRole = $this->createRoleWithPermissions(
            $workspace->id,
            'Assignable Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );

        $ipcDir = sys_get_temp_dir().'/workspace-access-auth-race-'.uniqid('', true);
        if (! mkdir($ipcDir) && ! is_dir($ipcDir)) {
            $this->fail('Could not create IPC directory.');
        }

        $phpBinary = PHP_BINARY;
        $workerScript = base_path('tests/Support/WorkspaceAccessMutationConcurrencyWorker.php');

        $processA = new Process([
            $phpBinary,
            $workerScript,
            'auth-race-a',
            $workspace->id,
            $actor->id,
            $ipcDir,
        ], base_path());
        $processA->setTimeout(120);
        $processA->start();

        $this->waitForIpcFile($ipcDir.'/a_lock_acquired');

        $processB = new Process([
            $phpBinary,
            $workerScript,
            'auth-race-b',
            $workspace->id,
            $actor->id,
            $targetMembership->id,
            $targetRole->id,
            $ipcDir,
        ], base_path());
        $processB->setTimeout(120);
        $processB->start();

        $this->waitForIpcFile($ipcDir.'/b_before_service');
        touch($ipcDir.'/parent_release_a');

        $processA->wait();
        $processB->wait();

        $this->assertFileExists($ipcDir.'/a_committed', 'Process A did not commit successfully.');
        $this->assertFileDoesNotExist($ipcDir.'/a_failed');
        $this->assertSame(0, $processA->getExitCode(), $processA->getErrorOutput());

        $this->assertSame('unauthorized', file_get_contents($ipcDir.'/b_result'));
        $this->assertSame(0, $processB->getExitCode(), $processB->getErrorOutput());

        $this->assertDatabaseMissing('workspace_user_roles', [
            'workspace_user_id' => $targetMembership->id,
            'workspace_role_id' => $targetRole->id,
        ]);
        $this->assertFalse($actor->fresh()->is_active);
        $this->assertSame(1, app(WorkspaceAccessEffectiveHolderQuery::class)->countEffectiveHolders($workspace->id));
    }

    #[Test]
    public function delete_role_rejects_freshly_assigned_state_after_waiting_for_workspace_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        Artisan::call('migrate:fresh');
        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantManageWorkspaceAccess($workspace, $actor);
        $this->makeEffectiveHolder($workspace, User::factory()->create(), 'Backup Holder');

        $targetMembership = $this->makeWorkspaceMembership($workspace);
        $targetRole = $this->createRoleWithPermissions(
            $workspace->id,
            'Delete Target Role',
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );

        $ipcDir = sys_get_temp_dir().'/workspace-access-delete-role-'.uniqid('', true);
        if (! mkdir($ipcDir) && ! is_dir($ipcDir)) {
            $this->fail('Could not create IPC directory.');
        }

        $phpBinary = PHP_BINARY;
        $workerScript = base_path('tests/Support/WorkspaceAccessMutationConcurrencyWorker.php');

        $processA = new Process([
            $phpBinary,
            $workerScript,
            'delete-role-a',
            $workspace->id,
            $targetMembership->id,
            $targetRole->id,
            $ipcDir,
        ], base_path());
        $processA->setTimeout(120);
        $processA->start();

        $this->waitForIpcFile($ipcDir.'/a_lock_acquired');

        $processB = new Process([
            $phpBinary,
            $workerScript,
            'delete-role-b',
            $workspace->id,
            $actor->id,
            $targetRole->id,
            $ipcDir,
        ], base_path());
        $processB->setTimeout(120);
        $processB->start();

        $this->waitForIpcFile($ipcDir.'/b_before_service');
        touch($ipcDir.'/parent_release_a');

        $processA->wait();
        $processB->wait();

        $this->assertFileExists($ipcDir.'/a_committed', 'Process A did not commit successfully.');
        $this->assertFileDoesNotExist($ipcDir.'/a_failed');
        $this->assertSame(0, $processA->getExitCode(), $processA->getErrorOutput());

        $this->assertSame('role_still_assigned', file_get_contents($ipcDir.'/b_result'));
        $this->assertSame(0, $processB->getExitCode(), $processB->getErrorOutput());

        $this->assertDatabaseHas('workspace_roles', ['id' => $targetRole->id]);
        $this->assertDatabaseHas('workspace_user_roles', [
            'workspace_user_id' => $targetMembership->id,
            'workspace_role_id' => $targetRole->id,
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
