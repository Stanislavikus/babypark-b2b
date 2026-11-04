<?php

namespace App\Support\Connectors\AdobePaaS\EntityTrust;

use App\Support\Connectors\AdobePaaS\Command\AdobeProductObservedState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductParentObservedState;

final readonly class AdobeProductCandidateDiscoveryResult
{
    public function __construct(
        public int $logicalEntityId,
        public string $sku,
        public string $typeId,
        public ?AdobeProductObservedState $simpleObservedState = null,
        public ?AdobeProductParentObservedState $parentObservedState = null,
    ) {}
}
