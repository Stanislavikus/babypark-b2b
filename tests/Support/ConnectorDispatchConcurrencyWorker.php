<?php

/**
 * Child-process worker for Connector dispatch post-lock revocation proofs.
 *
 * Usage:
 *   php tests/Support/ConnectorDispatchConcurrencyWorker.php account-lock-a <accountId> <ipcDir>
 *   php tests/Support/ConnectorDispatchConcurrencyWorker.php connection-check-b <workspaceId> <accountId> <actorUserId> <ipcDir>
 *   php tests/Support/ConnectorDispatchConcurrencyWorker.php discovery-b <workspaceId> <accountId> <actorUserId> <ipcDir>
 *   php tests/Support/ConnectorDispatchConcurrencyWorker.php workspace-lock-a <workspaceId> <ipcDir>
 */

use App\Models\ConnectorAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use App\Services\Connectors\ConnectorDiscoveryRunDispatchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
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
    'workspace-lock-a' => runWorkspaceLockA($argv[2] ?? '', $argv[3] ?? ''),
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

function configureConnectorDispatchTestEnvironment(bool $discovery = false): void
{
    Config::set('connectors.profiles.adobe_commerce_paas_oauth1_integration.capabilities', $discovery
        ? ['connection_check', 'schema_discovery']
        : ['connection_check']);

    if ($discovery) {
        Config::set('connectors.discovery.manual_trigger_enabled', true);
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
    configureConnectorDispatchTestEnvironment();

    $actor = User::query()->findOrFail($actorUserId);

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
    configureConnectorDispatchTestEnvironment(discovery: true);

    $actor = User::query()->findOrFail($actorUserId);

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

function runWorkspaceLockA(string $workspaceId, string $ipcDir): void
{
    assertIpcDir($ipcDir);

    DB::transaction(function () use ($workspaceId, $ipcDir): void {
        Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();

        touch($ipcDir.'/workspace_lock_acquired');
        waitForFile($ipcDir.'/parent_release_workspace');
    });

    touch($ipcDir.'/workspace_lock_committed');
    exit(0);
}
