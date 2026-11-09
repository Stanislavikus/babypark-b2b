<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\ConnectorConnectionCheckResult;

interface AdobePaaSConnectionCheckCapability
{
    public function checkConnection(AdobePaaSRequestContext $context): ConnectorConnectionCheckResult;
}
