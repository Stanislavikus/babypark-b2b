<?php

namespace App\Services\Pricing;

readonly class ResolvedPrice
{
    public function __construct(
        public float $regularNetPrice,
        public ?float $salePrice,
        public float $effectiveNetPrice,
        public float $vatRate,
        public float $grossPrice,
        public string $currency,
        public string $source,
    ) {}

    public static function fromListItem(
        float $regularNetPrice,
        ?float $salePrice,
        ?float $vatRate,
        string $currency,
        string $source,
    ): self {
        $resolvedVatRate = $vatRate ?? (float) config('pricing.default_vat_rate', 20);
        $effectiveNetPrice = $salePrice ?? $regularNetPrice;
        $grossPrice = round($effectiveNetPrice * (1 + $resolvedVatRate / 100), 2);

        return new self(
            regularNetPrice: $regularNetPrice,
            salePrice: $salePrice,
            effectiveNetPrice: $effectiveNetPrice,
            vatRate: $resolvedVatRate,
            grossPrice: $grossPrice,
            currency: $currency,
            source: $source,
        );
    }

    public static function fromBasePriceCache(
        float $baseNetPrice,
        string $currency,
    ): self {
        $vatRate = (float) config('pricing.default_vat_rate', 20);
        $grossPrice = round($baseNetPrice * (1 + $vatRate / 100), 2);

        return new self(
            regularNetPrice: $baseNetPrice,
            salePrice: null,
            effectiveNetPrice: $baseNetPrice,
            vatRate: $vatRate,
            grossPrice: $grossPrice,
            currency: $currency,
            source: 'base_price_cache',
        );
    }
}
