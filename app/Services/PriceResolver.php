<?php

namespace App\Services;

use App\Models\Contractor;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * Single source of truth for every price read in the app.
 *
 * Nothing else should read the product_prices table directly: callers ask this
 * resolver instead. All values are returned as decimal strings (never float —
 * money + float = rounding bugs), and any arithmetic the resolver performs uses
 * bcmath rather than native float operators.
 */
class PriceResolver
{
    /** Cache of price_type code => id for the lifetime of this resolver instance. */
    private array $typeIds = [];

    public function contractPrice(ProductVariant $variant, Contractor $contractor): ?string
    {
        return $this->value($variant, PriceType::CODE_CONTRACT_PRICE, $contractor->id);
    }

    /** РРЦ — contractor-less recommended retail price. */
    public function listPrice(ProductVariant $variant): ?string
    {
        return $this->value($variant, PriceType::CODE_LIST_PRICE, null);
    }

    /** Вхідна ціна — contractor-less cost of goods sold. */
    public function costOfGoodsSold(ProductVariant $variant): ?string
    {
        return $this->value($variant, PriceType::CODE_COST_OF_GOODS_SOLD, null);
    }

    /**
     * Margin for a variant = list price − cost of goods sold (bcmath).
     * Negative when cost exceeds list price. Null if either side is missing.
     */
    public function margin(ProductVariant $variant): ?string
    {
        $list = $this->listPrice($variant);
        $cost = $this->costOfGoodsSold($variant);

        if ($list === null || $cost === null) {
            return null;
        }

        return bcsub($list, $cost, 2);
    }

    /**
     * The variant of a product with the lowest contract price for this contractor.
     *
     * Returns the VARIANT (not a bare price) on purpose: callers that need the price
     * call contractPrice() on the returned variant, guaranteeing "which variant is
     * cheapest" and "what is that variant's price" always come from the same resolved
     * object instead of two separate lookups that could diverge.
     */
    public function minContractPriceAcrossVariants(Product $product, Contractor $contractor): ?ProductVariant
    {
        $best = null;
        $bestPrice = null;

        foreach ($this->activeVariants($product) as $variant) {
            $price = $this->contractPrice($variant, $contractor);
            if ($price === null) {
                continue;
            }

            if ($bestPrice === null || bccomp($price, $bestPrice, 2) < 0) {
                $best = $variant;
                $bestPrice = $price;
            }
        }

        return $best;
    }

    /** The highest list price (РРЦ) across a product's variants. Replaces Product::maxRrp(). */
    public function maxListPriceAcrossVariants(Product $product): ?string
    {
        $max = null;

        foreach ($this->activeVariants($product) as $variant) {
            $price = $this->listPrice($variant);
            if ($price === null) {
                continue;
            }

            if ($max === null || bccomp($price, $max, 2) > 0) {
                $max = $price;
            }
        }

        return $max;
    }

    /**
     * Resolve a single value for a variant/type/contractor combination, preferring the
     * already-loaded productPrices relation to avoid N+1 queries in list contexts.
     */
    private function value(ProductVariant $variant, string $code, ?int $contractorId): ?string
    {
        $typeId = $this->typeId($code);
        if ($typeId === null) {
            return null;
        }

        $row = $this->productPrices($variant)->first(
            fn ($p) => (int) $p->price_type_id === $typeId
                && (
                    ($contractorId === null && $p->contractor_id === null)
                    || ($contractorId !== null && (int) $p->contractor_id === $contractorId)
                )
        );

        if ($row === null || $row->value === null) {
            return null;
        }

        // Normalize to a 2-decimal string without ever going through float.
        return bcadd((string) $row->value, '0', 2);
    }

    /** @return Collection<int, ProductPrice> */
    private function productPrices(ProductVariant $variant): Collection
    {
        if ($variant->relationLoaded('productPrices')) {
            return $variant->productPrices;
        }

        return $variant->productPrices()->get();
    }

    /** @return Collection<int, ProductVariant> */
    private function activeVariants(Product $product): Collection
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants
            : $product->variants()->get();

        return $variants->filter(fn (ProductVariant $v) => (bool) $v->is_active)->values();
    }

    private function typeId(string $code): ?int
    {
        if (! array_key_exists($code, $this->typeIds)) {
            $this->typeIds[$code] = PriceType::query()->where('code', $code)->value('id');
        }

        return $this->typeIds[$code];
    }
}
