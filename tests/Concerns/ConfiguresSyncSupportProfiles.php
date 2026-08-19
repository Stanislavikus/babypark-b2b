<?php

namespace Tests\Concerns;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Support\Connectors\ConnectorProfileRegistry;
use Illuminate\Contracts\Container\Container;
use Tests\Support\Connectors\TestSyncSupportConnectorAccountSchema;
use Tests\Support\Connectors\TestSyncSupportConnectorAdapter;
use Tests\Support\Sync\TestFieldOptionMappingOptionValidator;
use Tests\Support\Sync\TestSyncPreviewCapability;

trait ConfiguresSyncSupportProfiles
{
    /**
     * @param  list<array{0: SyncDataDomain, 1: SyncSemanticOperation, 2: SyncRunMode}>  $supportedTriples
     */
    protected function configureSyncSupportProfile(array $supportedTriples = []): void
    {
        $container = app(Container::class);

        $profiles = config('connectors.profiles', []);
        $profiles['test_sync_support'] = [
            'enabled' => true,
            'connector_definition_code' => 'adobe_commerce',
            'adapter' => TestSyncSupportConnectorAdapter::class,
            'account_schema' => TestSyncSupportConnectorAccountSchema::class,
            'capabilities' => [],
            'preview_capability' => TestSyncPreviewCapability::class,
            'field_option_mapping_validator' => TestFieldOptionMappingOptionValidator::class,
        ];

        $container->instance(ConnectorProfileRegistry::class, new ConnectorProfileRegistry(
            $container,
            $profiles,
        ));

        $container->bind(
            TestSyncSupportConnectorAdapter::class,
            fn (): TestSyncSupportConnectorAdapter => new TestSyncSupportConnectorAdapter($supportedTriples),
        );
    }
}
