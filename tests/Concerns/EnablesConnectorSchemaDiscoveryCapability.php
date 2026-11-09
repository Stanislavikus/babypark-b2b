<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Config;

trait EnablesConnectorSchemaDiscoveryCapability
{
    protected function enableSchemaDiscoveryCapability(): void
    {
        Config::set('connectors.profiles.adobe_commerce_paas_oauth1_integration.capabilities', [
            'connection_check',
            'schema_discovery',
        ]);
    }
}
