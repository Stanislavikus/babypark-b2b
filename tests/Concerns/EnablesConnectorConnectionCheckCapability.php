<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Config;

trait EnablesConnectorConnectionCheckCapability
{
    protected function enableConnectionCheckCapability(): void
    {
        Config::set('connectors.profiles.adobe_commerce_paas_oauth1_integration.capabilities', [
            'connection_check',
        ]);
    }
}
