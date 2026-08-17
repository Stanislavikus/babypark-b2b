<?php

use App\Support\Connectors\AdobePaaS\AdobePaaSAccountSchema;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectorAdapter;
use App\Support\Connectors\AdobePaaS\AdobeProductExportPreviewCapability;

return [
    'discovery' => [
        'manual_trigger_enabled' => env(
            'CONNECTOR_DISCOVERY_MANUAL_TRIGGER_ENABLED',
            false,
        ),
    ],

    'profiles' => [
        'adobe_commerce_paas_oauth1_integration' => [
            'enabled' => true,
            'connector_definition_code' => 'adobe_commerce',
            'adapter' => AdobePaaSConnectorAdapter::class,
            'account_schema' => AdobePaaSAccountSchema::class,
            'capabilities' => [
                'connection_check',
                'schema_discovery',
                'account_setup',
            ],
            'preview_capability' => AdobeProductExportPreviewCapability::class,
        ],
    ],
];
