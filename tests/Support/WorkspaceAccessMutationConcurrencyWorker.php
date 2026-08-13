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
use App\Services\Workspace\WorkspaceAccessEffectiveHolderQuery;
use App\Services\Workspace\WorkspaceAccessMutationCoordinator;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessLockoutException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
    DB::beginTransaction();

    try {
        Workspace::query()
            ->whereKey($workspaceId)
            ->lockForUpdate()
            ->firstOrFail();

        WorkspaceUser::query()
            ->whereKey($holderAId)
            ->update(['is_active' => false]);

        touch($ipcDir.'/a_holding_lock');

        $deadline = time() + 60;
        while (! file_exists($ipcDir.'/b_entered') && time() < $deadline) {
            usleep(50_000);
        }

        if (! file_exists($ipcDir.'/b_entered')) {
            throw new RuntimeException('Timed out waiting for concurrent process B to enter coordinator.');
        }

        usleep(200_000);

        $effectiveHolderQuery = app(WorkspaceAccessEffectiveHolderQuery::class);
        if (! $effectiveHolderQuery->hasEffectiveHolder($workspaceId)) {
            throw new WorkspaceAccessLockoutException;
        }

        DB::commit();
        touch($ipcDir.'/a_committed');
        exit(0);
    } catch (Throwable $exception) {
        DB::rollBack();
        file_put_contents($ipcDir.'/a_failed', $exception->getMessage());
        exit(1);
    }
}

function runTransactionB(string $workspaceId, string $holderBId, string $ipcDir): void
{
    touch($ipcDir.'/b_entered');

    $workspace = Workspace::query()->findOrFail($workspaceId);
    $coordinator = app(WorkspaceAccessMutationCoordinator::class);

    try {
        $coordinator->mutateLocked($workspace, function () use ($holderBId): void {
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
