<?php

/**
 * Child-process worker for WorkspaceAccessMutationConcurrencyTest.
 *
 * Usage:
 *   php tests/Support/WorkspaceAccessMutationConcurrencyWorker.php transaction-a <workspaceId> <holderAId> <ipcDir>
 *   php tests/Support/WorkspaceAccessMutationConcurrencyWorker.php transaction-b <workspaceId> <holderBId> <ipcDir>
 *   php tests/Support/WorkspaceAccessMutationConcurrencyWorker.php auth-race-a <workspaceId> <actorUserId> <ipcDir>
 *   php tests/Support/WorkspaceAccessMutationConcurrencyWorker.php auth-race-b <workspaceId> <actorUserId> <membershipId> <roleId> <ipcDir>
 *   php tests/Support/WorkspaceAccessMutationConcurrencyWorker.php delete-role-a <workspaceId> <membershipId> <roleId> <ipcDir>
 *   php tests/Support/WorkspaceAccessMutationConcurrencyWorker.php delete-role-b <workspaceId> <actorUserId> <roleId> <ipcDir>
 */

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Workspace\WorkspaceAccessMutationCoordinator;
use App\Services\Workspace\WorkspaceAccessMutationService;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessLockoutException;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessMutationRejectedException;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessUnauthorizedException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$basePath = dirname(__DIR__, 2);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';

match ($mode) {
    'transaction-a' => runTransactionA($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''),
    'transaction-b' => runTransactionB($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''),
    'auth-race-a' => runAuthRaceA($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''),
    'auth-race-b' => runAuthRaceB($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? '', $argv[6] ?? ''),
    'delete-role-a' => runDeleteRoleA($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? ''),
    'delete-role-b' => runDeleteRoleB($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? ''),
    default => throw new InvalidArgumentException("Unknown worker mode: {$mode}"),
};

function assertIpcDir(string $ipcDir): void
{
    if ($ipcDir === '' || ! is_dir($ipcDir)) {
        fwrite(STDERR, "IPC directory does not exist: {$ipcDir}\n");
        exit(2);
    }
}

function waitForFile(string $path, int $seconds = 60): void
{
    $deadline = time() + $seconds;
    while (! file_exists($path) && time() < $deadline) {
        usleep(50_000);
    }

    if (! file_exists($path)) {
        throw new RuntimeException("Timed out waiting for {$path}");
    }
}

function runTransactionA(string $workspaceId, string $holderAId, string $ipcDir): void
{
    assertIpcDir($ipcDir);

    $workspace = Workspace::query()->findOrFail($workspaceId);
    $coordinator = app(WorkspaceAccessMutationCoordinator::class);

    try {
        $coordinator->mutateLocked($workspace, function () use ($ipcDir, $holderAId): void {
            touch($ipcDir.'/a_lock_acquired');

            waitForFile($ipcDir.'/b_before_coordinator');
            waitForFile($ipcDir.'/parent_release_a');

            WorkspaceUser::query()
                ->whereKey($holderAId)
                ->update(['is_active' => false]);
        });

        touch($ipcDir.'/a_committed');
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/a_failed', $exception->getMessage());
        exit(1);
    }
}

function runTransactionB(string $workspaceId, string $holderBId, string $ipcDir): void
{
    assertIpcDir($ipcDir);

    waitForFile($ipcDir.'/a_lock_acquired');
    touch($ipcDir.'/b_before_coordinator');

    $workspace = Workspace::query()->findOrFail($workspaceId);
    $coordinator = app(WorkspaceAccessMutationCoordinator::class);

    try {
        $coordinator->mutateLocked($workspace, function () use ($ipcDir, $holderBId): void {
            touch($ipcDir.'/b_mutator_executed');

            WorkspaceUser::query()
                ->whereKey($holderBId)
                ->update(['is_active' => false]);
        });

        file_put_contents($ipcDir.'/b_result', 'committed');
        exit(0);
    } catch (WorkspaceAccessLockoutException) {
        file_put_contents($ipcDir.'/b_result', 'lockout');
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/b_result', 'error:'.$exception->getMessage());
        exit(1);
    }
}

function runAuthRaceA(string $workspaceId, string $actorUserId, string $ipcDir): void
{
    assertIpcDir($ipcDir);

    $workspace = Workspace::query()->findOrFail($workspaceId);
    $coordinator = app(WorkspaceAccessMutationCoordinator::class);

    try {
        $coordinator->mutateLocked($workspace, function () use ($ipcDir, $actorUserId): void {
            touch($ipcDir.'/a_lock_acquired');

            waitForFile($ipcDir.'/b_before_service');
            waitForFile($ipcDir.'/parent_release_a');

            User::query()
                ->whereKey($actorUserId)
                ->update(['is_active' => false]);
        });

        touch($ipcDir.'/a_committed');
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/a_failed', $exception->getMessage());
        exit(1);
    }
}

function runAuthRaceB(string $workspaceId, string $actorUserId, string $membershipId, string $roleId, string $ipcDir): void
{
    assertIpcDir($ipcDir);

    $actor = User::query()->findOrFail($actorUserId);
    $workspace = Workspace::query()->findOrFail($workspaceId);

    waitForFile($ipcDir.'/a_lock_acquired');
    touch($ipcDir.'/b_before_service');

    try {
        app(WorkspaceAccessMutationService::class)->assignRole(
            $actor,
            $workspace,
            $membershipId,
            $roleId,
        );

        file_put_contents($ipcDir.'/b_result', 'committed');
        exit(0);
    } catch (WorkspaceAccessUnauthorizedException) {
        file_put_contents($ipcDir.'/b_result', 'unauthorized');
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/b_result', 'error:'.$exception->getMessage());
        exit(1);
    }
}

function runDeleteRoleA(string $workspaceId, string $membershipId, string $roleId, string $ipcDir): void
{
    assertIpcDir($ipcDir);

    $workspace = Workspace::query()->findOrFail($workspaceId);
    $coordinator = app(WorkspaceAccessMutationCoordinator::class);

    try {
        $coordinator->mutateLocked($workspace, function () use ($ipcDir, $workspace, $membershipId, $roleId): void {
            touch($ipcDir.'/a_lock_acquired');

            waitForFile($ipcDir.'/b_before_service');
            waitForFile($ipcDir.'/parent_release_a');

            DB::table('workspace_user_roles')->insert([
                'workspace_id' => $workspace->id,
                'workspace_user_id' => $membershipId,
                'workspace_role_id' => $roleId,
            ]);
        });

        touch($ipcDir.'/a_committed');
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/a_failed', $exception->getMessage());
        exit(1);
    }
}

function runDeleteRoleB(string $workspaceId, string $actorUserId, string $roleId, string $ipcDir): void
{
    assertIpcDir($ipcDir);

    $actor = User::query()->findOrFail($actorUserId);
    $workspace = Workspace::query()->findOrFail($workspaceId);

    waitForFile($ipcDir.'/a_lock_acquired');
    touch($ipcDir.'/b_before_service');

    try {
        app(WorkspaceAccessMutationService::class)->deleteRole(
            $actor,
            $workspace,
            $roleId,
        );

        file_put_contents($ipcDir.'/b_result', 'committed');
        exit(0);
    } catch (WorkspaceAccessMutationRejectedException) {
        file_put_contents($ipcDir.'/b_result', 'role_still_assigned');
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/b_result', 'error:'.$exception->getMessage());
        exit(1);
    }
}
