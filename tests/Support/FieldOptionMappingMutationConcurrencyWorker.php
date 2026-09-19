<?php

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\FieldOptionMapping;
use App\Models\SyncConfiguration;
use App\Services\Sync\FieldOptionMappingMutationService;
use App\Services\Sync\SyncConfigurationMutationCoordinator;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Sync\Exceptions\FieldOptionMappingStaleMutationException;
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
        [SyncDataDomain::Products, SyncSemanticOperation::Import],
        [SyncDataDomain::Products, SyncSemanticOperation::Export],
    ]),
);

$mode = $argv[1] ?? null;
$connectorAccountId = $argv[2] ?? null;
$configurationId = $argv[3] ?? null;
$fieldMappingId = $argv[4] ?? null;
$ipcDir = $argv[5] ?? null;

if (! $mode || ! $connectorAccountId || ! $configurationId || ! $fieldMappingId || ! $ipcDir) {
    fwrite(STDERR, "Missing worker arguments.\n");
    exit(2);
}

$account = ConnectorAccount::withoutWorkspaceScope()->findOrFail($connectorAccountId);
$mutationService = app(FieldOptionMappingMutationService::class);
$coordinator = app(SyncConfigurationMutationCoordinator::class);

if ($mode === 'hold-lock') {
    DB::transaction(function () use ($account, $configurationId, $ipcDir): void {
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
    });

    file_put_contents($ipcDir.'/lock_released', '1');
    exit(0);
}

if ($mode === 'confirm-blue') {
    file_put_contents($ipcDir.'/confirm_before_coordinator', '1');

    $deadline = time() + 30;
    while (time() < $deadline) {
        if (is_file($ipcDir.'/lock_acquired')) {
            break;
        }

        usleep(50_000);
    }

    try {
        $mutationService->confirm(
            $account,
            $configurationId,
            $fieldMappingId,
            'blue',
            '93',
        );
        file_put_contents($ipcDir.'/confirm_result', 'success');
    } catch (FieldOptionMappingStaleMutationException) {
        file_put_contents($ipcDir.'/confirm_result', 'stale');
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/confirm_failed', $exception->getMessage());
        exit(1);
    }

    file_put_contents($ipcDir.'/confirm_finished', '1');
    exit(0);
}

if ($mode === 'hold-lock-delete') {
    DB::transaction(function () use ($account, $configurationId, $fieldMappingId, $ipcDir, $coordinator): void {
        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('id', $configurationId)
            ->lockForUpdate()
            ->firstOrFail();

        file_put_contents($ipcDir.'/lock_acquired', '1');

        $deadline = time() + 30;
        while (time() < $deadline) {
            if (is_file($ipcDir.'/stale_remove_started')) {
                break;
            }

            usleep(50_000);
        }

        $optionMapping = FieldOptionMapping::withoutWorkspaceScope()
            ->where('field_mapping_id', $fieldMappingId)
            ->where('internal_option_key', 'blue')
            ->first();

        if ($optionMapping !== null) {
            $optionMapping->delete();
            $coordinator->refreshConfigurationRevision($configuration);
        }

        file_put_contents($ipcDir.'/mapping_deleted', '1');
    });

    file_put_contents($ipcDir.'/lock_released', '1');
    exit(0);
}

if ($mode === 'stale-remove') {
    file_put_contents($ipcDir.'/stale_remove_started', '1');

    try {
        $mutationService->remove(
            $account,
            $configurationId,
            $fieldMappingId,
            'blue',
            '93',
        );
        file_put_contents($ipcDir.'/stale_remove_result', 'success');
    } catch (FieldOptionMappingStaleMutationException) {
        file_put_contents($ipcDir.'/stale_remove_result', 'stale');
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/stale_remove_failed', $exception->getMessage());
        exit(1);
    }

    file_put_contents($ipcDir.'/stale_remove_finished', '1');
    exit(0);
}

fwrite(STDERR, "Unknown worker mode: {$mode}\n");
exit(2);
