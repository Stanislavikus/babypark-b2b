<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeProductSimpleCommandResult
{
    public function __construct(
        public AdobeProductAppliedStateKnowledge $appliedStateKnowledge,
        public AdobeProductCommandSafeEvidence $evidence,
    ) {}

    public function consequentialWriteWasAttempted(): bool
    {
        return $this->evidence->consequentialWriteAttempts > 0;
    }
}
