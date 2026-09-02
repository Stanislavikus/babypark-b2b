<?php

namespace App\Support\Connectors\AdobePaaS;

interface AdobeMagentoVersionProbeCapability
{
    public function probe(#[\SensitiveParameter] AdobePaaSRequestContext $context): ?string;
}
