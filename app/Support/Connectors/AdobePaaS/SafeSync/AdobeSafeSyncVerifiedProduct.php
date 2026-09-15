<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

final readonly class AdobeSafeSyncVerifiedProduct
{
    public function __construct(
        public int $logicalEntityId,
        public string $sku,
        public string $typeId,
        public string $name,
    ) {}
}
