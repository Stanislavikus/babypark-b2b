<?php

namespace App\Support\ProductStructure;

final readonly class ProductCompletenessProjection
{
    /**
     * @param  list<ProductCompletenessGroupProjection>  $groups
     * @param  list<string>  $missingProductBindingIds
     * @param  list<array{variant_id:int,field_binding_id:string}>  $missingVariantCells
     * @param  list<string>  $activeOptionalGroupPlacementIds
     * @param  list<string>  $inactiveOptionalGroupPlacementIds
     */
    public function __construct(
        public string $workspaceId,
        public int $productId,
        public string $productTypeId,
        public int $structureRevision,
        public string $locale,
        public int $requiredCount,
        public int $filledCount,
        public int $percentage,
        public array $groups,
        public array $missingProductBindingIds,
        public array $missingVariantCells,
        public array $activeOptionalGroupPlacementIds,
        public array $inactiveOptionalGroupPlacementIds,
    ) {}
}
