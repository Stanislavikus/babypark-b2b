<?php

namespace App\Support\Connectors\AdobePaaS\EntityTrust;

use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncVerifiedProduct;

final readonly class AdobeProductEntityTrustVerifiedSubject
{
    public function __construct(
        public int $logicalEntityId,
        public string $sku,
        public string $typeId,
        public string $name,
        public AdobeProductCandidateDiscoveryResult $candidate,
        public AdobeSafeSyncVerifiedProduct $verified,
    ) {}

    public function discriminator(): string
    {
        return (string) $this->logicalEntityId;
    }
}
