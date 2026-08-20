<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeConfigurableCommandEvidence
{
    public function __construct(
        public string $commandKind,
        public AdobeProductAppliedStateKnowledge $appliedStateKnowledge,
        public string $reasonCode,
        public ?string $subjectSku = null,
        public ?string $variantId = null,
        public ?int $attributeId = null,
        public ?int $configurableOptionId = null,
        public int $consequentialWriteAttempts = 0,
        public int $reconciliationGetAttempts = 0,
        public bool $externalRecordLinkPersisted = false,
        public bool $ownershipTrustSatisfied = false,
    ) {}
}
