<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeProductCreateOwnershipEvidence
{
    public function __construct(
        public AdobeProductRemoteGetClassification $preWriteClassification,
        public AdobeProductCreateWriteEvidence $writeEvidence,
        public bool $responseIdentityConfirmed,
    ) {}

    public static function inconclusive(
        AdobeProductRemoteGetClassification $preWriteClassification,
    ): self {
        return new self(
            preWriteClassification: $preWriteClassification,
            writeEvidence: AdobeProductCreateWriteEvidence::Inconclusive,
            responseIdentityConfirmed: false,
        );
    }

    public static function definitiveCreate(
        AdobeProductRemoteGetClassification $preWriteClassification,
    ): self {
        return new self(
            preWriteClassification: $preWriteClassification,
            writeEvidence: AdobeProductCreateWriteEvidence::DefinitiveSuccess,
            responseIdentityConfirmed: true,
        );
    }
}
