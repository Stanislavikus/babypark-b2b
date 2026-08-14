<?php

/**
 * Child-process worker for Connector dispatch post-lock revocation proofs.
 *
 * Usage:
 *   php tests/Support/ConnectorDispatchConcurrencyWorker.php account-lock-a <accountId> <ipcDir>
 *   php tests/Support/ConnectorDispatchConcurrencyWorker.php connection-check-b <workspaceId> <accountId> <actorUserId> <ipcDir>
 *   php tests/Support/ConnectorDispatchConcurrencyWorker.php discovery-b <workspaceId> <accountId> <actorUserId> <ipcDir>
 *   php tests/Support/ConnectorDispatchConcurrencyWorker.php revoke-actor <actorUserId> <ipcDir>
 */

use App\Models\ConnectorAccount;
use App\Models\User;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use App\Services\Connectors\ConnectorDiscoveryRunDispatchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$basePath = dirname(__DIR__, 2);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';

match ($mode) {
    'account-lock-a' => runAccountLockA($argv[2] ?? '', $argv[3] ?? ''),
    'connection-check-b' => runConnectionCheckB($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? ''),
    'discovery-b' => runDiscoveryB($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? ''),
    'revoke-actor' => runRevokeActor($argv[2] ?? '', $argv[3] ?? ''),
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

function runAccountLockA(string $accountId, string $ipcDir): void
{
    assertIpcDir($ipcDir);

    DB::transaction(function () use ($accountId, $ipcDir): void {
        ConnectorAccount::withoutWorkspaceScope()
            ->whereKey($accountId)
            ->lockForUpdate()
            ->firstOrFail();

        touch($ipcDir.'/a_lock_acquired');
        waitForFile($ipcDir.'/parent_release_a');
    });

    touch($ipcDir.'/a_committed');
    exit(0);
}

function runConnectionCheckB(string $workspaceId, string $accountId, string $actorUserId, string $ipcDir): void
{
    assertIpcDir($ipcDir);

    $actor = User::query()->findOrFail($actorUserId);
    touch($ipcDir.'/b_started');

    try {
        app(ConnectorConnectionCheckDispatchService::class)->executeManual($actor, $workspaceId, $accountId);
        file_put_contents($ipcDir.'/b_result', 'dispatched');
        exit(0);
    } catch (AuthorizationException) {
        file_put_contents($ipcDir.'/b_result', 'unauthorized');
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/b_result', 'error:'.$exception->getMessage());
        exit(1);
    }
}

function runDiscoveryB(string $workspaceId, string $accountId, string $actorUserId, string $ipcDir): void
{
    assertIpcDir($ipcDir);

    $actor = User::query()->findOrFail($actorUserId);
    touch($ipcDir.'/b_started');

    try {
        app(ConnectorDiscoveryRunDispatchService::class)->executeManual($actor, $workspaceId, $accountId);
        file_put_contents($ipcDir.'/b_result', 'dispatched');
        exit(0);
    } catch (AuthorizationException) {
        file_put_contents($ipcDir.'/b_result', 'unauthorized');
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/b_result', 'error:'.$exception->getMessage());
        exit(1);
    }
}

function runRevokeActor(string $actorUserId, string $ipcDir): void
{
    assertIpcDir($ipcDir);

    waitForFile($ipcDir.'/b_started');

    DB::transaction(function () use ($actorUserId): void {
        User::query()->whereKey($actorUserId)->update(['is_active' => false]);
    });

    touch($ipcDir.'/actor_revoked');
    exit(0);
}
