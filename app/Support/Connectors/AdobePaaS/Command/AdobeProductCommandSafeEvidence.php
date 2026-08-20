<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeProductCommandSafeEvidence
{
    public function __construct(
        public string $reasonCode,
        public ?string $subjectSku = null,
        public ?AdobeProductRemoteGetClassification $remoteGetClassification = null,
        public int $consequentialWriteAttempts = 0,
        public int $reconciliationGetAttempts = 0,
        public bool $externalRecordLinkPersisted = false,
        public bool $ownershipTrustSatisfied = false,
    ) {}
}
