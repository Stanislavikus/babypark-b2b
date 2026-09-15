<?php

namespace App\Support\ProductStructure;

final readonly class ProductTypeChangeImpact
{
    public function __construct(
        public string $workspaceId,
        public int $productId,
        public string $fromProductTypeId,
        public int $fromStructureRevision,
        public string $toProductTypeId,
        public int $toStructureRevision,
        public array $addedBindingIds,
        public array $removedBindingIds,
        public array $addedAttributeGroupIds,
        public array $removedAttributeGroupIds,
        public array $outOfTypeProductBindingIds,
        public array $outOfTypeVariantCells,
        public array $invalidOptionalOverrideIds,
        public array $newlyRequiredBindingIds,
        public array $affectedActiveVariantIds,
        public string $completenessProjectionStatus = 'deferred_to_slice_b',
        public ?int $completenessBefore = null,
        public ?int $completenessAfter = null,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(get_object_vars($this), JSON_THROW_ON_ERROR));
    }
}
