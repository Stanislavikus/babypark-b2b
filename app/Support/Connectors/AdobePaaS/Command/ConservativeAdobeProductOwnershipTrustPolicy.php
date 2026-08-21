<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final class ConservativeAdobeProductOwnershipTrustPolicy implements AdobeProductOwnershipTrustPolicy
{
    public function canPersistNewLink(
        AdobeProductDesiredState $desiredState,
        AdobeProductObservedState $observedState,
        AdobeProductCreateOwnershipEvidence $ownershipEvidence,
    ): bool {
        return $this->approvesCreateProvenance($ownershipEvidence);
    }

    public function canPersistNewParentLink(
        AdobeProductParentDesiredState $desiredState,
        AdobeProductParentObservedState $observedState,
        AdobeProductCreateOwnershipEvidence $ownershipEvidence,
    ): bool {
        return $this->approvesCreateProvenance($ownershipEvidence);
    }

    private function approvesCreateProvenance(AdobeProductCreateOwnershipEvidence $ownershipEvidence): bool
    {
        return $ownershipEvidence->preWriteClassification === AdobeProductRemoteGetClassification::TrustedKnownMissing
            && $ownershipEvidence->writeEvidence === AdobeProductCreateWriteEvidence::DefinitiveSuccess
            && $ownershipEvidence->responseIdentityConfirmed;
    }
}
