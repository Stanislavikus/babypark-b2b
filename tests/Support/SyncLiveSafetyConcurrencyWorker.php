<?php

use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Services\Sync\SyncLiveAdmissionService;
use App\Services\Workspace\WorkspaceAccessMutationCoordinator;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Sync\Exceptions\SyncLiveAdmissionException;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Tests\Support\Connectors\TestSyncSupportConnectorAccountSchema;
use Tests\Support\Connectors\TestSyncSupportConnectorAdapter;
use Tests\Support\Sync\TestFieldOptionMappingOptionValidator;
use Tests\Support\Sync\TestSyncPreviewCapability;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$container = app(Container::class);
$container->instance(ConnectorProfileRegistry::class, new ConnectorProfileRegistry(
    $container,
    [
        'test_sync_support' => [
            'enabled' => true,
            'connector_definition_code' => 'adobe_commerce',
            'adapter' => TestSyncSupportConnectorAdapter::class,
            'account_schema' => TestSyncSupportConnectorAccountSchema::class,
            'capabilities' => [],
            'preview_capability' => TestSyncPreviewCapability::class,
            'field_option_mapping_validator' => TestFieldOptionMappingOptionValidator::class,
        ],
    ],
));
$container->bind(
    TestSyncSupportConnectorAdapter::class,
    fn (): TestSyncSupportConnectorAdapter => new TestSyncSupportConnectorAdapter([
        [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
        [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview],
        [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Live],
    ]),
);

$mode = $argv[1] ?? null;
$workspaceId = $argv[2] ?? null;
$connectorAccountId = $argv[3] ?? null;
$configurationId = $argv[4] ?? null;
$actorId = $argv[5] ?? null;
$ipcDir = $argv[6] ?? null;
$extraArg = $argv[7] ?? null;

if (! $mode || ! $workspaceId || ! $connectorAccountId || ! $configurationId || ! $actorId || ! $ipcDir) {
    fwrite(STDERR, "Missing worker arguments.\n");
    exit(2);
}

$account = ConnectorAccount::withoutWorkspaceScope()->findOrFail($connectorAccountId);
$actor = User::query()->findOrFail($actorId);
$workspace = Workspace::query()->findOrFail($workspaceId);
$liveAdmission = app(SyncLiveAdmissionService::class);

match ($mode) {
    'config-lock-blocker' => runConfigLockBlocker($account, $configurationId, $ipcDir),
    'concurrent-live-a' => runConcurrentLiveA($account, $configurationId, $actor, $ipcDir, $liveAdmission),
    'concurrent-live-b' => runConcurrentLiveB($account, $configurationId, $actor, $ipcDir, $liveAdmission),
    'recovery-admit-a' => runRecoveryAdmitA($account, $configurationId, $actor, $ipcDir, $liveAdmission),
    'recovery-admit-b' => runRecoveryAdmitB($account, $configurationId, $actor, $ipcDir, $liveAdmission),
    'rbac-revoke-live-a' => runRbacRevokeLiveA($workspace, $extraArg ?? '', $ipcDir),
    'rbac-revoke-live-b' => runRbacRevokeLiveB($account, $configurationId, $actor, $ipcDir, $liveAdmission),
    'live-admit-rbac-a' => runLiveAdmitRbacA($account, $configurationId, $actor, $ipcDir, $liveAdmission),
    'rbac-revoke-live-after-a' => runRbacRevokeLiveAfterA($workspace, $extraArg ?? '', $ipcDir),
    default => throw new InvalidArgumentException("Unknown worker mode: {$mode}"),
};

function assertIpcDir(string $ipcDir): void
{
    if ($ipcDir === '' || ! is_dir($ipcDir)) {
        fwrite(STDERR, "IPC directory does not exist: {$ipcDir}\n");
        exit(2);
    }
}

function waitForIpcFile(string $path, int $seconds = 60): void
{
    $deadline = time() + $seconds;
    while (! file_exists($path) && time() < $deadline) {
        usleep(50_000);
    }

    if (! file_exists($path)) {
        throw new RuntimeException("Timed out waiting for {$path}");
    }
}

function runConfigLockBlocker(
    ConnectorAccount $account,
    string $configurationId,
    string $ipcDir,
): void {
    assertIpcDir($ipcDir);

    DB::transaction(function () use ($account, $configurationId, $ipcDir): void {
        SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('id', $configurationId)
            ->lockForUpdate()
            ->firstOrFail();

        file_put_contents($ipcDir.'/lock_acquired', '1');

        $deadline = time() + 60;
        while (time() < $deadline) {
            if (is_file($ipcDir.'/release_lock')) {
                break;
            }

            usleep(100_000);
        }
    });

    exit(0);
}

function runConcurrentLiveA(
    ConnectorAccount $account,
    string $configurationId,
    User $actor,
    string $ipcDir,
    SyncLiveAdmissionService $liveAdmission,
): void {
    assertIpcDir($ipcDir);

    file_put_contents($ipcDir.'/a_entered_admit', '1');

    try {
        $liveAdmission->admit($actor, $account, $configurationId);
        file_put_contents($ipcDir.'/a_admitted', '1');
        exit(0);
    } catch (SyncLiveAdmissionException $exception) {
        file_put_contents($ipcDir.'/a_result', 'error:'.$exception->getMessage());
        exit(1);
    }
}

function runConcurrentLiveB(
    ConnectorAccount $account,
    string $configurationId,
    User $actor,
    string $ipcDir,
    SyncLiveAdmissionService $liveAdmission,
): void {
    assertIpcDir($ipcDir);

    waitForIpcFile($ipcDir.'/lock_acquired');
    file_put_contents($ipcDir.'/b_entered_admit', '1');

    try {
        $liveAdmission->admit($actor, $account, $configurationId);
        file_put_contents($ipcDir.'/b_result', 'unexpected_success');
        exit(1);
    } catch (SyncLiveAdmissionException $exception) {
        file_put_contents($ipcDir.'/b_result', $exception->getMessage());
        exit(0);
    }
}

function runRecoveryAdmitA(
    ConnectorAccount $account,
    string $configurationId,
    User $actor,
    string $ipcDir,
    SyncLiveAdmissionService $liveAdmission,
): void {
    runConcurrentLiveA($account, $configurationId, $actor, $ipcDir, $liveAdmission);
}

function runRecoveryAdmitB(
    ConnectorAccount $account,
    string $configurationId,
    User $actor,
    string $ipcDir,
    SyncLiveAdmissionService $liveAdmission,
): void {
    runConcurrentLiveB($account, $configurationId, $actor, $ipcDir, $liveAdmission);
}

function runRbacRevokeLiveA(Workspace $workspace, string $liveRoleId, string $ipcDir): void
{
    assertIpcDir($ipcDir);

    $permissionId = WorkspacePermission::query()
        ->where('code', WorkspacePermissions::RUN_SYNC_LIVE)
        ->value('id');

    $coordinator = app(WorkspaceAccessMutationCoordinator::class);

    try {
        $coordinator->mutateLocked($workspace, function () use ($ipcDir, $liveRoleId, $permissionId, $workspace): void {
            touch($ipcDir.'/a_lock_acquired');

            DB::table('workspace_role_permissions')
                ->where('workspace_id', $workspace->id)
                ->where('workspace_role_id', $liveRoleId)
                ->where('workspace_permission_id', $permissionId)
                ->delete();

            waitForIpcFile($ipcDir.'/b_before_admit');
            waitForIpcFile($ipcDir.'/parent_release_a');
        });

        touch($ipcDir.'/a_committed');
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/a_failed', $exception->getMessage());
        exit(1);
    }
}

function runRbacRevokeLiveB(
    ConnectorAccount $account,
    string $configurationId,
    User $actor,
    string $ipcDir,
    SyncLiveAdmissionService $liveAdmission,
): void {
    assertIpcDir($ipcDir);

    waitForIpcFile($ipcDir.'/a_lock_acquired');
    touch($ipcDir.'/b_before_admit');

    try {
        $liveAdmission->admit($actor, $account, $configurationId);
        file_put_contents($ipcDir.'/b_result', 'unexpected_success');
        exit(1);
    } catch (SyncLiveAdmissionException $exception) {
        file_put_contents($ipcDir.'/b_result', $exception->getMessage());
        exit(0);
    }
}

function runLiveAdmitRbacA(
    ConnectorAccount $account,
    string $configurationId,
    User $actor,
    string $ipcDir,
    SyncLiveAdmissionService $liveAdmission,
): void {
    assertIpcDir($ipcDir);

    try {
        $run = $liveAdmission->admit($actor, $account, $configurationId);
        file_put_contents($ipcDir.'/a_result', $run->id);
        exit(0);
    } catch (SyncLiveAdmissionException $exception) {
        file_put_contents($ipcDir.'/a_result', 'error:'.$exception->getMessage());
        exit(1);
    }
}

function runRbacRevokeLiveAfterA(Workspace $workspace, string $liveRoleId, string $ipcDir): void
{
    assertIpcDir($ipcDir);

    waitForIpcFile($ipcDir.'/a_result');

    $permissionId = WorkspacePermission::query()
        ->where('code', WorkspacePermissions::RUN_SYNC_LIVE)
        ->value('id');

    $coordinator = app(WorkspaceAccessMutationCoordinator::class);

    try {
        $coordinator->mutateLocked($workspace, function () use ($liveRoleId, $permissionId, $workspace): void {
            DB::table('workspace_role_permissions')
                ->where('workspace_id', $workspace->id)
                ->where('workspace_role_id', $liveRoleId)
                ->where('workspace_permission_id', $permissionId)
                ->delete();
        });

        file_put_contents($ipcDir.'/b_result', 'revoked');
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/b_result', 'error:'.$exception->getMessage());
        exit(1);
    }
}
