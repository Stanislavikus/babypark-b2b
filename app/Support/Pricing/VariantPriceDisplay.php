<?php

namespace App\Support\Pricing;

use App\Enums\CatalogPriceDisplayStatus;
use App\Models\ProductVariant;
use App\Services\Pricing\Resolution\PriceResolutionReason;
use App\Services\Pricing\ResolvedPrice;

readonly class VariantPriceDisplay
{
    private function __construct(
        public CatalogPriceDisplayStatus $status,
        public bool $available,
        public float $grossPrice,
        public float $regularNetPrice,
        public float $effectiveNetPrice,
        public float $vatRate,
        public ?float $salePrice,
        public ?float $recommendedRetailPrice,
        public string $currency,
        public string $source,
        public ?string $sourcePriceListId,
        public ?string $sourcePriceListItemId,
        public float $regularGrossPrice,
        public bool $isOnSale,
        public ?PriceResolutionReason $reason,
        public ?ProductVariant $sourceVariant,
        public ?ResolvedPrice $resolvedPrice,
    ) {}

    public static function fromResolved(
        ResolvedPrice $resolved,
        ?float $recommendedRetailPrice = null,
        ?ProductVariant $sourceVariant = null,
    ): self {
        return new self(
            status: CatalogPriceDisplayStatus::Resolved,
            available: true,
            grossPrice: $resolved->grossPrice,
            regularNetPrice: $resolved->regularNetPrice,
            effectiveNetPrice: $resolved->effectiveNetPrice,
            vatRate: $resolved->vatRate,
            salePrice: $resolved->salePrice,
            recommendedRetailPrice: $recommendedRetailPrice,
            currency: $resolved->currency,
            source: $resolved->source,
            sourcePriceListId: $resolved->sourcePriceListId,
            sourcePriceListItemId: $resolved->sourcePriceListItemId,
            regularGrossPrice: $resolved->regularGrossPrice,
            isOnSale: $resolved->isOnSale,
            reason: null,
            sourceVariant: $sourceVariant,
            resolvedPrice: $resolved,
        );
    }

    public static function unavailable(?PriceResolutionReason $reason = null): self
    {
        return new self(
            status: CatalogPriceDisplayStatus::Unavailable,
            available: false,
            grossPrice: 0.0,
            regularNetPrice: 0.0,
            effectiveNetPrice: 0.0,
            vatRate: 0.0,
            salePrice: null,
            recommendedRetailPrice: null,
            currency: (string) config('pricing.default_currency', 'UAH'),
            source: 'unavailable',
            sourcePriceListId: null,
            sourcePriceListItemId: null,
            regularGrossPrice: 0.0,
            isOnSale: false,
            reason: $reason ?? PriceResolutionReason::AllSourcesExhausted,
            sourceVariant: null,
            resolvedPrice: null,
        );
    }

    public static function configurationError(
        PriceResolutionReason $reason,
        ProductVariant $attemptedVariant,
    ): self {
        return new self(
            status: CatalogPriceDisplayStatus::ConfigurationError,
            available: false,
            grossPrice: 0.0,
            regularNetPrice: 0.0,
            effectiveNetPrice: 0.0,
            vatRate: 0.0,
            salePrice: null,
            recommendedRetailPrice: null,
            currency: (string) config('pricing.default_currency', 'UAH'),
            source: 'configuration_error',
            sourcePriceListId: null,
            sourcePriceListItemId: null,
            regularGrossPrice: 0.0,
            isOnSale: false,
            reason: $reason,
            sourceVariant: $attemptedVariant,
            resolvedPrice: null,
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
