<?php

namespace App\Services\Pricing\Resolution;

final readonly class PriceResolutionStep
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public PriceResolutionSource $source,
        public PriceResolutionStepStatus $status,
        public PriceResolutionReason $reason,
        public ?string $priceListId = null,
        public ?string $priceListItemId = null,
        public ?float $amount = null,
        public ?string $currency = null,
        public array $metadata = [],
    ) {}
}
