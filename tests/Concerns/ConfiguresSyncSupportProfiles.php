<?php

namespace Tests\Concerns;

use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Support\Connectors\AdobePaaS\AdobeProductExportPreviewCapability;
use App\Support\Connectors\ConnectorProfileRegistry;
use Illuminate\Contracts\Container\Container;
use Tests\Support\Connectors\TestSyncSupportConnectorAccountSchema;
use Tests\Support\Connectors\TestSyncSupportConnectorAdapter;
use Tests\Support\Sync\TestFieldOptionMappingOptionValidator;
use Tests\Support\Sync\TestSyncLiveCapability;
use Tests\Support\Sync\TestSyncPreviewCapability;

trait ConfiguresSyncSupportProfiles
{
    /**
     * @param  list<array{0: SyncDataDomain, 1: SyncSemanticOperation, 2?: SyncRunMode}>  $supportedTriples
     */
    protected function configureSyncSupportProfile(array $supportedTriples = []): void
    {
        $normalizedTriples = array_map(
            static function (array $entry): array {
                if (count($entry) === 2) {
                    return [$entry[0], $entry[1], SyncRunMode::Preview];
                }

                return $entry;
            },
            $supportedTriples,
        );

        $container = app(Container::class);

        $profiles = config('connectors.profiles', []);
        $profiles['test_sync_support'] = [
            'enabled' => true,
            'connector_definition_code' => 'adobe_commerce',
            'adapter' => TestSyncSupportConnectorAdapter::class,
            'account_schema' => TestSyncSupportConnectorAccountSchema::class,
            'capabilities' => [],
            'preview_capability' => TestSyncPreviewCapability::class,
            'live_capability' => TestSyncLiveCapability::class,
            'field_option_mapping_validator' => TestFieldOptionMappingOptionValidator::class,
        ];

        $container->instance(ConnectorProfileRegistry::class, new ConnectorProfileRegistry(
            $container,
            $profiles,
        ));

        $container->bind(
            TestSyncSupportConnectorAdapter::class,
            fn (): TestSyncSupportConnectorAdapter => new TestSyncSupportConnectorAdapter($normalizedTriples),
        );
    }

    /**
     * Adobe Products Export profile identity with injectable mode-aware support truth.
     *
     * @param  list<array{0: SyncDataDomain, 1: SyncSemanticOperation, 2?: SyncRunMode}>  $supportedTriples
     */
    protected function configureAdobeProductsExportSyncSupportProfile(array $supportedTriples = []): void
    {
        $normalizedTriples = array_map(
            static function (array $entry): array {
                if (count($entry) === 2) {
                    return [$entry[0], $entry[1], SyncRunMode::Preview];
                }

                return $entry;
            },
            $supportedTriples,
        );

        $container = app(Container::class);

        $profiles = config('connectors.profiles', []);
        $profiles['test_adobe_products_export_sync_support'] = [
            'enabled' => true,
            'connector_definition_code' => 'adobe_commerce',
            'adapter' => TestSyncSupportConnectorAdapter::class,
            'account_schema' => TestSyncSupportConnectorAccountSchema::class,
            'capabilities' => [],
            'preview_capability' => AdobeProductExportPreviewCapability::class,
            'live_capability' => TestSyncLiveCapability::class,
            'field_option_mapping_validator' => TestFieldOptionMappingOptionValidator::class,
        ];

        $container->instance(ConnectorProfileRegistry::class, new ConnectorProfileRegistry(
            $container,
            $profiles,
        ));

        $container->bind(
            TestSyncSupportConnectorAdapter::class,
            fn (): TestSyncSupportConnectorAdapter => new TestSyncSupportConnectorAdapter($normalizedTriples),
        );
    }

    /**
     * @param  list<array{0: SyncDataDomain, 1: SyncSemanticOperation, 2?: SyncRunMode}>  $supportedTriples
     */
    protected function configureGenericNonAdobePreviewProfile(array $supportedTriples = []): void
    {
        $normalizedTriples = array_map(
            static function (array $entry): array {
                if (count($entry) === 2) {
                    return [$entry[0], $entry[1], SyncRunMode::Preview];
                }

                return $entry;
            },
            $supportedTriples,
        );

        $container = app(Container::class);

        $profiles = config('connectors.profiles', []);
        $profiles['test_generic_non_adobe_preview'] = [
            'enabled' => true,
            'connector_definition_code' => 'adobe_commerce',
            'adapter' => TestSyncSupportConnectorAdapter::class,
            'account_schema' => TestSyncSupportConnectorAccountSchema::class,
            'capabilities' => [],
            'preview_capability' => TestSyncPreviewCapability::class,
            'live_capability' => TestSyncLiveCapability::class,
            'field_option_mapping_validator' => TestFieldOptionMappingOptionValidator::class,
        ];

        $container->instance(ConnectorProfileRegistry::class, new ConnectorProfileRegistry(
            $container,
            $profiles,
        ));

        $container->bind(
            TestSyncSupportConnectorAdapter::class,
            fn (): TestSyncSupportConnectorAdapter => new TestSyncSupportConnectorAdapter($normalizedTriples),
        );
    }
}
