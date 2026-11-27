<?php

namespace App\Services\Pricing;

use App\Enums\CatalogPriceDisplayStatus;
use App\Enums\PriceDisplayContext;
use App\Exceptions\Pricing\PriceListConfigurationException;
use App\Exceptions\Pricing\PriceNotAvailableException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Pricing\VariantPriceDisplay;
use App\Support\Workspace\WorkspaceContext;
use Illuminate\Support\Collection;

class ProductPricingSummary
{
    public function __construct(
        private readonly PriceResolver $priceResolver,
    ) {}

    /**
     * @return Collection<int, float>
     */
    public function activeVariantCostPrices(Product $product): Collection
    {
        return $product->variants
            ->where('is_active', true)
            ->pluck('cost_price')
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => (float) $value)
            ->values();
    }

    /**
     * @return Collection<int, float>
     */
    public function activeVariantRrpValues(Product $product): Collection
    {
        return $product->variants
            ->where('is_active', true)
            ->pluck('recommended_retail_price_cache')
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => (float) $value)
            ->values();
    }

    public function maxRrp(Product $product): ?float
    {
        $values = $this->activeVariantRrpValues($product);

        return $values->isEmpty() ? null : $values->max();
    }

    public function formatCostPrice(Product $product): ?string
    {
        return self::formatMoneyRange($this->activeVariantCostPrices($product));
    }

    public function formatRrp(Product $product): ?string
    {
        return self::formatMoneyRange($this->activeVariantRrpValues($product));
    }

    /**
     * @param  Collection<int, float>  $values
     */
    public static function formatMoneyRange(Collection $values): ?string
    {
        if ($values->isEmpty()) {
            return null;
        }

        $min = $values->min();
        $max = $values->max();

        if ($min === $max) {
            return '₴ '.number_format($min, 2, '.', ' ');
        }

        return '₴ '.number_format($min, 2, '.', ' ')
            .'–'.number_format($max, 2, '.', ' ');
    }

    public function resolveVariantDisplay(
        ProductVariant $variant,
        Customer $customer,
        int $quantity = 1,
        ?PriceResolutionSnapshot $snapshot = null,
    ): VariantPriceDisplay {
        try {
            $resolved = $this->priceResolver->resolveForCustomer(
                $variant,
                $customer,
                $quantity,
                snapshot: $snapshot,
            );
            $rrp = $variant->recommended_retail_price_cache !== null
                ? (float) $variant->recommended_retail_price_cache
                : null;

            return VariantPriceDisplay::fromResolved($resolved, $rrp, $variant);
        } catch (PriceNotAvailableException $exception) {
            return VariantPriceDisplay::unavailable();
        } catch (PriceListConfigurationException $exception) {
            return VariantPriceDisplay::configurationError($exception->reason, $variant);
        }
    }

    public function tryResolveVariantDisplay(
        ProductVariant $variant,
        Customer $customer,
        int $quantity = 1,
        ?PriceResolutionSnapshot $snapshot = null,
    ): ?VariantPriceDisplay {
        $display = $this->resolveVariantDisplay($variant, $customer, $quantity, $snapshot);

        return $display->available ? $display : null;
    }

    /**
     * @param  Collection<int, VariantPriceDisplay>  $resolvedDisplaysByVariantId
     */
    public function cheapestResolvedDisplay(Collection $resolvedDisplaysByVariantId): ?VariantPriceDisplay
    {
        if ($resolvedDisplaysByVariantId->isEmpty()) {
            return null;
        }

        $entries = $resolvedDisplaysByVariantId->all();
        usort($entries, static function (VariantPriceDisplay $a, VariantPriceDisplay $b): int {
            $variantIdA = $a->sourceVariant?->id ?? PHP_INT_MAX;
            $variantIdB = $b->sourceVariant?->id ?? PHP_INT_MAX;

            return ($a->grossPrice <=> $b->grossPrice) ?: ($variantIdA <=> $variantIdB);
        });

        return $entries[0] ?? null;
    }

    public function minGrossPriceForCustomer(
        Product $product,
        Customer $customer,
        ?PriceResolutionSnapshot $snapshot = null,
    ): ?VariantPriceDisplay {
        $resolved = $product->variants
            ->where('is_active', true)
            ->mapWithKeys(fn (ProductVariant $variant): array => [
                $variant->id => $this->resolveVariantDisplay($variant, $customer, 1, $snapshot),
            ])
            ->filter(fn (VariantPriceDisplay $display): bool => $display->status === CatalogPriceDisplayStatus::Resolved);

        return $this->cheapestResolvedDisplay($resolved);
    }

    public function formatCustomerGrossPrice(
        Product $product,
        Customer $customer,
        ?PriceResolutionSnapshot $snapshot = null,
    ): ?string {
        $prices = $product->variants
            ->where('is_active', true)
            ->map(fn (ProductVariant $variant) => $this->tryResolveVariantDisplay($variant, $customer, 1, $snapshot)?->grossPrice)
            ->filter()
            ->values();

        return self::formatMoneyRange($prices);
    }

    public function variantHasResolvablePrice(
        ProductVariant $variant,
        Customer $customer,
        int $quantity = 1,
        ?PriceResolutionSnapshot $snapshot = null,
    ): bool {
        return $this->tryResolveVariantDisplay($variant, $customer, $quantity, $snapshot) !== null;
    }

    public function productHasResolvablePrice(Product $product, Customer $customer): bool
    {
        return $product->variants
            ->where('is_active', true)
            ->contains(fn (ProductVariant $variant) => $this->variantHasResolvablePrice($variant, $customer));
    }

    public function resolveDefaultDisplay(
        ProductVariant $variant,
        int $quantity = 1,
        ?PriceResolutionSnapshot $snapshot = null,
    ): VariantPriceDisplay {
        try {
            $resolved = $this->priceResolver->resolveDefault($variant, $quantity, snapshot: $snapshot);
            $rrp = $variant->recommended_retail_price_cache !== null
                ? (float) $variant->recommended_retail_price_cache
                : null;

            return VariantPriceDisplay::fromResolved($resolved, $rrp, $variant);
        } catch (PriceNotAvailableException) {
            return VariantPriceDisplay::unavailable();
        } catch (PriceListConfigurationException $exception) {
            return VariantPriceDisplay::configurationError($exception->reason, $variant);
        }
    }

    public function formatDefaultSalePrice(Product $product, ?PriceResolutionSnapshot $snapshot = null): ?string
    {
        $cheapestDisplay = null;
        $cheapestGross = null;

        foreach ($product->variants->where('is_active', true) as $variant) {
            $display = $this->resolveDefaultDisplay($variant, 1, $snapshot);

            if ($display->available && $display->resolvedPrice !== null) {
                if ($cheapestGross === null || $display->grossPrice < $cheapestGross) {
                    $cheapestGross = $display->grossPrice;
                    $cheapestDisplay = $display;
                }
            }
        }

        if ($cheapestDisplay === null || $cheapestDisplay->resolvedPrice === null) {
            return null;
        }

        $workspace = app(WorkspaceContext::class)->current();
        $mode = app(PriceDisplayModeResolver::class)->resolve($workspace, PriceDisplayContext::Internal);
        $presentation = app(PriceDisplayPresenter::class)->present($cheapestDisplay->resolvedPrice, $mode);

        return $presentation->compactLabel();
    }
}
