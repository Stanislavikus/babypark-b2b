<?php

namespace App\Support;

use App\Models\Contractor;
use App\Models\Product;
use App\Models\ProductVariant;

class CatalogRowData
{
    /**
     * Compute catalog row data for a product (requires eager-loaded relations).
     *
     * @return array{
     *     badge: array{label: string, color: string},
     *     firstVariant: ?ProductVariant,
     *     maxQty: int,
     *     minQty: int,
     *     defaultQty: int,
     *     step: int,
     *     myPrice: ?float,
     *     rrp: ?float,
     * }
     */
    public static function forProduct(Product $product, Contractor $contractor): array
    {
        $threshold = $product->category?->stock_display_threshold ?? 10;
        $activeVariants = $product->variants->where('is_active', true);
        $variantsWithPrice = $activeVariants->filter(
            fn ($v) => $v->prices->where('contractor_id', $contractor->id)->isNotEmpty()
        );
        $firstVariant = $variantsWithPrice->first();

        $totalAvailQty = $activeVariants->sum(
            fn ($v) => $v->stocks->sum(fn ($s) => $s->quantity - ($s->reserved ?? 0))
        );
        $totalExpQty = $activeVariants->sum(fn ($v) => $v->stocks->sum('expected_quantity')) ?? 0;
        $earliestExpDate = $activeVariants
            ->flatMap(fn ($v) => $v->stocks)
            ->whereNotNull('expected_date')
            ->sortBy('expected_date')
            ->first()
            ?->expected_date;

        $badge = ProductVariant::badgeFromQty($totalAvailQty, $totalExpQty, $earliestExpDate, $threshold);

        $variantAvailQty = $firstVariant
            ? $firstVariant->stocks->sum(fn ($s) => $s->quantity - ($s->reserved ?? 0))
            : 0;
        $variantExpQty = $firstVariant
            ? ($firstVariant->stocks->sum('expected_quantity') ?? 0)
            : 0;

        $maxQty = match ($badge['color']) {
            'success', 'warning' => $variantAvailQty,
            'info' => $variantExpQty,
            default => 0,
        };

        $minQty = max(1, $product->min_order_quantity);
        $realQtyForDefault = match ($badge['color']) {
            'success', 'warning' => $variantAvailQty,
            'info' => $variantExpQty,
            default => 0,
        };

        return [
            'badge' => $badge,
            'firstVariant' => $firstVariant,
            'maxQty' => $maxQty,
            'minQty' => $minQty,
            'defaultQty' => self::defaultDisplayQty($realQtyForDefault, $threshold, $minQty, $maxQty),
            'step' => max(1, $product->order_step),
            'myPrice' => $product->minPriceFor($contractor),
            'rrp' => $product->maxRrp(),
        ];
    }

    /**
     * Starting quantity for the stepper — mirrors the stock badge threshold rule.
     * Ceiling (max) stays at real available stock; only the initial display is capped.
     */
    public static function defaultDisplayQty(int $realQty, int $threshold, int $minQty, int $maxQty): int
    {
        if ($maxQty <= 0) {
            return $minQty;
        }

        $base = $realQty > $threshold ? $threshold : $realQty;

        return max($minQty, min($maxQty, $base));
    }
}
