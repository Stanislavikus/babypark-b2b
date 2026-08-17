<?php

namespace App\Support\Sync\Preview;

use App\Services\Pricing\ResolvedPrice;

final readonly class ProductVariantExecutionSlice
{
    /**
     * @param  array<string, MappedFieldValue>  $values  keyed by field_binding_id
     */
    public function __construct(
        public string $variantId,
        public array $values,
        public ?ResolvedPrice $resolvedPrice,
        public ?string $priceResolutionStatus,
    ) {}
}

final readonly class ProductExecutionAggregate
{
    /**
     * @param  array<string, MappedFieldValue>  $productValues  keyed by field_binding_id
     * @param  list<ProductVariantExecutionSlice>  $variants
     */
    public function __construct(
        public string $productId,
        public array $productValues,
        public array $variants,
        public int $sellableVariantCount,
    ) {}

    public function hasSellableVariants(): bool
    {
        return $this->sellableVariantCount > 0;
    }

    public function hasMultipleSellableVariants(): bool
    {
        return $this->sellableVariantCount > 1;
    }
}
