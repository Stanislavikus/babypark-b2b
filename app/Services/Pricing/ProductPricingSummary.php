<?php

namespace App\Services\Pricing;

use App\Exceptions\Pricing\PriceNotAvailableException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Pricing\VariantPriceDisplay;
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
    ): VariantPriceDisplay {
        try {
            $resolved = $this->priceResolver->resolveForCustomer($variant, $customer, $quantity);
            $rrp = $variant->recommended_retail_price_cache !== null
                ? (float) $variant->recommended_retail_price_cache
                : null;

            return VariantPriceDisplay::fromResolved($resolved, $rrp);
        } catch (PriceNotAvailableException) {
            return VariantPriceDisplay::unavailable();
        }
    }

    public function tryResolveVariantDisplay(
        ProductVariant $variant,
        Customer $customer,
        int $quantity = 1,
    ): ?VariantPriceDisplay {
        $display = $this->resolveVariantDisplay($variant, $customer, $quantity);

        return $display->available ? $display : null;
    }

    public function minGrossPriceForCustomer(Product $product, Customer $customer): ?float
    {
        $prices = $product->variants
            ->where('is_active', true)
            ->map(fn (ProductVariant $variant) => $this->tryResolveVariantDisplay($variant, $customer)?->grossPrice)
            ->filter()
            ->values();

        return $prices->isEmpty() ? null : $prices->min();
    }

    public function formatCustomerGrossPrice(Product $product, Customer $customer): ?string
    {
        $prices = $product->variants
            ->where('is_active', true)
            ->map(fn (ProductVariant $variant) => $this->tryResolveVariantDisplay($variant, $customer)?->grossPrice)
            ->filter()
            ->values();

        return self::formatMoneyRange($prices);
    }

    public function variantHasResolvablePrice(ProductVariant $variant, Customer $customer, int $quantity = 1): bool
    {
        return $this->tryResolveVariantDisplay($variant, $customer, $quantity) !== null;
    }

    public function productHasResolvablePrice(Product $product, Customer $customer): bool
    {
        return $product->variants
            ->where('is_active', true)
            ->contains(fn (ProductVariant $variant) => $this->variantHasResolvablePrice($variant, $customer));
    }

    public function resolveDefaultDisplay(ProductVariant $variant, int $quantity = 1): VariantPriceDisplay
    {
        try {
            $resolved = $this->priceResolver->resolveDefault($variant, $quantity);
            $rrp = $variant->recommended_retail_price_cache !== null
                ? (float) $variant->recommended_retail_price_cache
                : null;

            return VariantPriceDisplay::fromResolved($resolved, $rrp);
        } catch (PriceNotAvailableException) {
            return VariantPriceDisplay::unavailable();
        }
    }

    public function formatDefaultSalePrice(Product $product): ?string
    {
        $prices = $product->variants
            ->where('is_active', true)
            ->map(fn (ProductVariant $variant) => $this->resolveDefaultDisplay($variant)->available
                ? $this->resolveDefaultDisplay($variant)->grossPrice
                : null)
            ->filter()
            ->values();

        return self::formatMoneyRange($prices);
    }
}
