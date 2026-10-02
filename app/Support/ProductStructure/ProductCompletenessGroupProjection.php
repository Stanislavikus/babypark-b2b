<?php

namespace App\Support\ProductStructure;

final readonly class ProductCompletenessGroupProjection
{
    /**
     * @param  list<string>  $missingProductBindingIds
     * @param  list<array{variant_id:int,field_binding_id:string}>  $missingVariantCells
     */
    public function __construct(
        public string $groupPlacementId,
        public string $attributeGroupId,
        public bool $isOptional,
        public bool $isActive,
        public int $requiredCount,
        public int $filledCount,
        public int $percentage,
        public array $missingProductBindingIds,
        public array $missingVariantCells,
    ) {}
}
