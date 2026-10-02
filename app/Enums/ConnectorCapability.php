<?php

namespace App\Enums;

enum ConnectorCapability: string
{
    case ConnectionCheck = 'connection_check';
    case SchemaDiscovery = 'schema_discovery';
    case AccountSetup = 'account_setup';
}
