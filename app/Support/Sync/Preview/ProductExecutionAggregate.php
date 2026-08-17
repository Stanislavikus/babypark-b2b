<?php

namespace App\Support\Sync\Preview;

use App\Models\Product;
use App\Services\Pricing\ResolvedPrice;

final readonly class ProductVariantExecutionSlice
{
    /**
     * @param  array<string, scalar|null>  $values  keyed by field_binding_id
     */
    public function __construct(
        public string $variantId,
        public string $sku,
        public array $values,
        public ?ResolvedPrice $resolvedPrice,
        public ?string $priceResolutionStatus,
    ) {}
}

final readonly class ProductExecutionAggregate
{
    /**
     * @param  array<string, scalar|null>  $productValues  keyed by field_binding_id
     * @param  list<ProductVariantExecutionSlice>  $variants
     */
    public function __construct(
        public Product $product,
        public array $productValues,
        public array $variants,
    ) {}

    public function isConfigurable(): bool
    {
        return $this->variants !== [];
    }
}
