<?php

namespace App\Services\Pricing;

use App\Enums\CatalogPriceDisplayStatus;
use App\Models\PriceList;
use App\Support\Pricing\CustomerFacingPriceLabel;
use App\Support\Pricing\VariantPriceDisplay;
use LogicException;

class PriceProvenancePresenter
{
    public function present(
        ResolvedPrice|VariantPriceDisplay $price,
        ?PriceList $sourcePriceList = null,
    ): ?PriceProvenancePresentation {
        if ($price instanceof VariantPriceDisplay && ! $price->available) {
            if ($price->status === CatalogPriceDisplayStatus::ConfigurationError) {
                return new PriceProvenancePresentation(
                    label: CustomerFacingPriceLabel::forDisplay($price),
                    source: $price->source,
                    isOnSale: false,
                    regularGrossPrice: 0.0,
                    effectiveGrossPrice: 0.0,
                    currency: $price->currency,
                    sourcePriceListId: null,
                    sourcePriceListItemId: null,
                );
            }

            return null;
        }

        $source = $price->source;

        if (in_array($source, ['customer_price_list', 'workspace_default_price_list'], true)) {
            $this->assertListBasedSource($price, $sourcePriceList);

            $label = $source === 'customer_price_list'
                ? "Індивідуальний прайс-лист «{$sourcePriceList->name}»"
                : 'Основний прайс-лист компанії';
        } elseif ($source === 'base_price_cache') {
            $this->assertBasePriceCacheSource($price, $sourcePriceList);

            $label = 'Базова ціна товару — в основному прайс-листі немає активної ціни для цієї позиції та кількості.';
        } else {
            throw new LogicException("Unrecognized price source: {$source}");
        }

        return new PriceProvenancePresentation(
            label: $label,
            source: $source,
            isOnSale: $price->isOnSale,
            regularGrossPrice: $price->regularGrossPrice,
            effectiveGrossPrice: $price->grossPrice,
            currency: $price->currency,
            sourcePriceListId: $price->sourcePriceListId,
            sourcePriceListItemId: $price->sourcePriceListItemId,
        );
    }

    private function assertListBasedSource(
        ResolvedPrice|VariantPriceDisplay $price,
        ?PriceList $sourcePriceList,
    ): void {
        if ($price->sourcePriceListId === null) {
            throw new LogicException('sourcePriceListId must not be null for list-based price sources.');
        }

        if ($price->sourcePriceListItemId === null) {
            throw new LogicException('sourcePriceListItemId must not be null for list-based price sources.');
        }

        if ($sourcePriceList === null) {
            throw new LogicException('sourcePriceList is required for list-based price sources.');
        }

        if ($sourcePriceList->id !== $price->sourcePriceListId) {
            throw new LogicException('sourcePriceList id does not match price sourcePriceListId.');
        }
    }

    private function assertBasePriceCacheSource(
        ResolvedPrice|VariantPriceDisplay $price,
        ?PriceList $sourcePriceList,
    ): void {
        if ($price->sourcePriceListId !== null) {
            throw new LogicException('sourcePriceListId must be null for base_price_cache source.');
        }

        if ($price->sourcePriceListItemId !== null) {
            throw new LogicException('sourcePriceListItemId must be null for base_price_cache source.');
        }

        if ($sourcePriceList !== null) {
            throw new LogicException('sourcePriceList must not be supplied for base_price_cache source.');
        }
    }
}
