<?php

namespace Tests\Concerns;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Support\Connectors\ConnectorProfileRegistry;
use Illuminate\Contracts\Container\Container;
use Tests\Support\Connectors\TestSyncSupportConnectorAccountSchema;
use Tests\Support\Connectors\TestSyncSupportConnectorAdapter;
use Tests\Support\Sync\TestSyncPreviewCapability;

trait ConfiguresSyncSupportProfiles
{
    /**
     * @param  list<array{0: SyncDataDomain, 1: SyncSemanticOperation}>  $supportedPairs
     */
    protected function configureSyncSupportProfile(array $supportedPairs = []): void
    {
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
                ],
            ],
        ));

        $container->bind(
            TestSyncSupportConnectorAdapter::class,
            fn (): TestSyncSupportConnectorAdapter => new TestSyncSupportConnectorAdapter($supportedPairs),
        );
    }
}
