<?php

namespace App\Support\Pricing;

use App\Services\Pricing\ResolvedPrice;

readonly class VariantPriceDisplay
{
    public function __construct(
        public bool $available,
        public float $grossPrice,
        public float $regularNetPrice,
        public ?float $salePrice,
        public ?float $recommendedRetailPrice,
        public string $currency,
        public string $source,
        public ?string $sourcePriceListId,
        public ?string $sourcePriceListItemId,
        public float $regularGrossPrice,
        public bool $isOnSale,
    ) {}

    public static function fromResolved(ResolvedPrice $resolved, ?float $recommendedRetailPrice = null): self
    {
        return new self(
            available: true,
            grossPrice: $resolved->grossPrice,
            regularNetPrice: $resolved->regularNetPrice,
            salePrice: $resolved->salePrice,
            recommendedRetailPrice: $recommendedRetailPrice,
            currency: $resolved->currency,
            source: $resolved->source,
            sourcePriceListId: $resolved->sourcePriceListId,
            sourcePriceListItemId: $resolved->sourcePriceListItemId,
            regularGrossPrice: $resolved->regularGrossPrice,
            isOnSale: $resolved->isOnSale,
        );
    }

    public static function unavailable(): self
    {
        return new self(
            available: false,
            grossPrice: 0.0,
            regularNetPrice: 0.0,
            salePrice: null,
            recommendedRetailPrice: null,
            currency: (string) config('pricing.default_currency', 'UAH'),
            source: 'unavailable',
            sourcePriceListId: null,
            sourcePriceListItemId: null,
            regularGrossPrice: 0.0,
            isOnSale: false,
        );
    }

    public function formattedGross(): string
    {
        return '₴ '.number_format($this->grossPrice, 2, '.', ' ');
    }

    public function formattedRrp(): ?string
    {
        if ($this->recommendedRetailPrice === null) {
            return null;
        }

        return '₴ '.number_format($this->recommendedRetailPrice, 2, '.', ' ');
    }
}
