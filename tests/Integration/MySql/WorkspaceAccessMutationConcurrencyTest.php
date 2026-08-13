<?php

namespace Tests\Integration\MySql;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Services\Workspace\WorkspaceAccessEffectiveHolderQuery;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WorkspaceAccessMutationConcurrencyTest extends TestCase
{
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
        $holderA = $this->makeEffectiveHolder($workspace, 'Concurrent Holder A');
        $holderB = $this->makeEffectiveHolder($workspace, 'Concurrent Holder B');

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
        while (! file_exists($ipcDir.'/a_holding_lock') && time() < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($ipcDir.'/a_holding_lock', 'Process A did not acquire workspace lock.');

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

        $processA->wait();
        $processB->wait();

        $this->assertFileExists($ipcDir.'/a_committed', 'Process A did not commit successfully.');
        $this->assertFileNotExists($ipcDir.'/a_failed');
        $this->assertSame(0, $processA->getExitCode(), $processA->getErrorOutput());

        $this->assertFileExists($ipcDir.'/b_entered', 'Process B did not enter coordinator.');
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

    private function makeEffectiveHolder(Workspace $workspace, string $roleName): WorkspaceUser
    {
        $user = User::factory()->create(['is_active' => true]);

        $membership = WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $role = WorkspaceRole::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $roleName,
        ]);

        $permission = WorkspacePermission::query()
            ->where('code', WorkspacePermissions::MANAGE_WORKSPACE_ACCESS)
            ->firstOrFail();

        DB::table('workspace_role_permissions')->insert([
            'workspace_id' => $workspace->id,
            'workspace_role_id' => $role->id,
            'workspace_permission_id' => $permission->id,
        ]);

        DB::table('workspace_user_roles')->insert([
            'workspace_id' => $workspace->id,
            'workspace_user_id' => $membership->id,
            'workspace_role_id' => $role->id,
        ]);

        return $membership;
    }
}
