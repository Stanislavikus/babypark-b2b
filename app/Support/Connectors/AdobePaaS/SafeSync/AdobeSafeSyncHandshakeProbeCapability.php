<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;

interface AdobeSafeSyncHandshakeProbeCapability
{
    public function probe(AdobePaaSRequestContext $context): AdobeSafeSyncHandshakeProbeResult;
}
