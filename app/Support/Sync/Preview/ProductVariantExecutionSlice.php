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
