<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\ConnectorDiscoveryAttemptResult;

interface AdobePaaSDiscoveryCapability
{
    public function discover(
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
        string $endpointPath,
    ): ConnectorDiscoveryAttemptResult;
}
