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

        return [
            'badge' => $badge,
            'firstVariant' => $firstVariant,
            'maxQty' => $maxQty,
            'minQty' => max(1, $product->min_order_quantity),
            'step' => max(1, $product->order_step),
            'myPrice' => $product->minPriceFor($contractor),
            'rrp' => $product->maxRrp(),
        ];
    }
}
