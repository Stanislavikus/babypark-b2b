<?php

namespace App\Support\Connectors\AdobePaaS\Command;

interface AdobeProductOwnershipTrustPolicy
{
    public function canPersistNewLink(
        AdobeProductDesiredState $desiredState,
        AdobeProductObservedState $observedState,
        AdobeProductCreateOwnershipEvidence $ownershipEvidence,
    ): bool;

    public function canPersistNewParentLink(
        AdobeProductParentDesiredState $desiredState,
        AdobeProductParentObservedState $observedState,
        AdobeProductCreateOwnershipEvidence $ownershipEvidence,
    ): bool;
}
