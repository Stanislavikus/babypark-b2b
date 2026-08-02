<?php

use App\Support\Connectors\AdobePaaS\AdobePaaSAccountSchema;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectorAdapter;

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
            'adapter' => AdobePaaSConnectorAdapter::class,
            'account_schema' => AdobePaaSAccountSchema::class,
            'capabilities' => ['connection_check', 'schema_discovery'],
        ],
    ],
];
