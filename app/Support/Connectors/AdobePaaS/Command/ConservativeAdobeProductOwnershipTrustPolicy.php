<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final class ConservativeAdobeProductOwnershipTrustPolicy implements AdobeProductOwnershipTrustPolicy
{
    public function canPersistNewLink(
        AdobeProductDesiredState $desiredState,
        AdobeProductObservedState $observedState,
    ): bool {
        return false;
    }
}
