<?php

namespace App\Support\Connectors\AdobePaaS\Command;

interface AdobeProductOwnershipTrustPolicy
{
    public function canPersistNewLink(
        AdobeProductDesiredState $desiredState,
        AdobeProductObservedState $observedState,
    ): bool;
}
