<?php

/**
 * Child-process worker for WorkspaceAccessMutationConcurrencyTest.
 *
 * Usage:
 *   php tests/Support/WorkspaceAccessMutationConcurrencyWorker.php transaction-a <workspaceId> <holderAId> <ipcDir>
 *   php tests/Support/WorkspaceAccessMutationConcurrencyWorker.php transaction-b <workspaceId> <holderBId> <ipcDir>
 */

use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Workspace\WorkspaceAccessMutationCoordinator;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessLockoutException;
use Illuminate\Contracts\Console\Kernel;

$basePath = dirname(__DIR__, 2);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$workspaceId = $argv[2] ?? '';
$holderId = $argv[3] ?? '';
$ipcDir = $argv[4] ?? '';

if ($mode === '' || $workspaceId === '' || $holderId === '' || $ipcDir === '') {
    fwrite(STDERR, 'Missing worker arguments.'."\n");
    exit(2);
}

if (! is_dir($ipcDir)) {
    fwrite(STDERR, "IPC directory does not exist: {$ipcDir}\n");
    exit(2);
}

match ($mode) {
    'transaction-a' => runTransactionA($workspaceId, $holderId, $ipcDir),
    'transaction-b' => runTransactionB($workspaceId, $holderId, $ipcDir),
    default => throw new InvalidArgumentException("Unknown worker mode: {$mode}"),
};

function runTransactionA(string $workspaceId, string $holderAId, string $ipcDir): void
{
    $workspace = Workspace::query()->findOrFail($workspaceId);
    $coordinator = app(WorkspaceAccessMutationCoordinator::class);

    try {
        $coordinator->mutateLocked($workspace, function () use ($ipcDir, $holderAId): void {
            touch($ipcDir.'/a_lock_acquired');

            $deadline = time() + 60;
            while (! file_exists($ipcDir.'/b_before_coordinator') && time() < $deadline) {
                usleep(50_000);
            }

            if (! file_exists($ipcDir.'/b_before_coordinator')) {
                throw new RuntimeException('Timed out waiting for process B to signal before coordinator.');
            }

            $deadline = time() + 60;
            while (! file_exists($ipcDir.'/parent_release_a') && time() < $deadline) {
                usleep(50_000);
            }

            if (! file_exists($ipcDir.'/parent_release_a')) {
                throw new RuntimeException('Timed out waiting for parent to release process A.');
            }

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
    $deadline = time() + 60;
    while (! file_exists($ipcDir.'/a_lock_acquired') && time() < $deadline) {
        usleep(50_000);
    }

    if (! file_exists($ipcDir.'/a_lock_acquired')) {
        file_put_contents($ipcDir.'/b_result', 'error:Timed out waiting for process A lock.');
        exit(1);
    }

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
