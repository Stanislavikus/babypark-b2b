<?php

use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Services\Sync\SyncLiveAdmissionService;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Sync\Exceptions\SyncLiveAdmissionException;
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

if (! $mode || ! $workspaceId || ! $connectorAccountId || ! $configurationId || ! $actorId || ! $ipcDir) {
    fwrite(STDERR, "Missing worker arguments.\n");
    exit(2);
}

$account = ConnectorAccount::withoutWorkspaceScope()->findOrFail($connectorAccountId);
$actor = User::query()->findOrFail($actorId);
$service = app(SyncLiveAdmissionService::class);

if ($mode === 'hold-lock') {
    DB::transaction(function () use ($account, $configurationId, $ipcDir, $actor, $service): void {
        SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('id', $configurationId)
            ->lockForUpdate()
            ->firstOrFail();

        file_put_contents($ipcDir.'/lock_acquired', '1');

        $deadline = time() + 30;
        while (time() < $deadline) {
            if (is_file($ipcDir.'/release_lock')) {
                break;
            }

            usleep(100_000);
        }

        $service->admit($actor, $account, $configurationId, SyncSemanticOperation::Export);
    });

    exit(0);
}

if ($mode === 'second-admit') {
    while (! is_file($ipcDir.'/lock_acquired')) {
        usleep(50_000);
    }

    try {
        $service->admit($actor, $account, $configurationId, SyncSemanticOperation::Export);
        fwrite(STDOUT, "SECOND_ADMIT_SUCCEEDED\n");
    } catch (SyncLiveAdmissionException $exception) {
        if (str_contains($exception->getMessage(), 'active sync run')) {
            fwrite(STDOUT, "ACTIVE_RUN_EXISTS\n");
            exit(0);
        }

        fwrite(STDERR, $exception->getMessage()."\n");
        exit(1);
    }

    exit(0);
}

fwrite(STDERR, "Unknown worker mode: {$mode}\n");
exit(2);
