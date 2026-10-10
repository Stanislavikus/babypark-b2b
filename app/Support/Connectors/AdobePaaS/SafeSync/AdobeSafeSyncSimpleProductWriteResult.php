<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;

final readonly class AdobeSafeSyncSimpleProductWriteResult
{
    /**
     * @param  list<string>  $warningCodes
     */
    public function __construct(
        public AdobeProductAppliedStateKnowledge $appliedStateKnowledge,
        public string $reasonCode,
        public int $logicalEntityId,
        public string $sku,
        public bool $postconditionVerified,
        public int $consequentialWriteAttempts,
        public array $warningCodes,
    ) {}
}
