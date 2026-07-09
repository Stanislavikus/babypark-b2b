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
