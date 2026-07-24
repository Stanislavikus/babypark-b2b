<?php

use App\Support\Connectors\AdobePaaS\AdobePaaSAccountSchema;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectorAdapter;

return [
    'profiles' => [
        'adobe_commerce_paas_oauth1_integration' => [
            'enabled' => true,
            'adapter' => AdobePaaSConnectorAdapter::class,
            'account_schema' => AdobePaaSAccountSchema::class,
            'capabilities' => [],
        ],
    ],
];
