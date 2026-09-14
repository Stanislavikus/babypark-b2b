<?php

namespace App\Support\ProductStructure;

final readonly class ProductTypeMutationResult
{
    public function __construct(
        public string $workspaceId,
        public int $actorId,
        public int $productId,
        public string $fromProductTypeId,
        public string $toProductTypeId,
        public string $impactFingerprint,
        public int $invalidOptionalOverridesRemoved,
    ) {}
}
