<?php

namespace App\Services\Pricing;

readonly class PriceProvenancePresentation
{
    public function __construct(
        public string $label,
        public string $source,
        public bool $isOnSale,
        public float $regularGrossPrice,
        public float $effectiveGrossPrice,
        public string $currency,
        public ?string $sourcePriceListId,
        public ?string $sourcePriceListItemId,
    ) {}
}
