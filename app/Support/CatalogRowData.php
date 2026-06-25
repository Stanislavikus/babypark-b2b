<?php

namespace App\Support;

use App\Models\Contractor;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\Carbon;

class CatalogRowData
{
    /**
     * Compute catalog row data for a product (requires eager-loaded relations).
     *
     * Selects one representative variant and derives badge, price, and maxQty only
     * from that variant — never mixing aggregate stock with a single variant's figures.
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
            fn (ProductVariant $v) => $v->prices->where('contractor_id', $contractor->id)->isNotEmpty()
        );

        $minQty = max(1, $product->min_order_quantity);
        $step = max(1, $product->order_step);
        $rrp = $product->maxRrp();

        // Step 1: in-stock priced variants — lowest price wins.
        $inStockVariants = $variantsWithPrice->filter(
            fn (ProductVariant $v) => self::variantAvailQty($v) > 0
        );

        if ($inStockVariants->isNotEmpty()) {
            $firstVariant = $inStockVariants
                ->sortBy(fn (ProductVariant $v) => $v->priceFor($contractor))
                ->first();

            $availQty = self::variantAvailQty($firstVariant);
            $badge = ProductVariant::badgeFromQty(
                $availQty,
                self::variantExpectedQty($firstVariant),
                self::variantEarliestExpectedDate($firstVariant),
                $threshold,
            );

            return [
                'badge' => $badge,
                'firstVariant' => $firstVariant,
                'maxQty' => $availQty,
                'minQty' => $minQty,
                'step' => $step,
                'myPrice' => $firstVariant->priceFor($contractor),
                'rrp' => $rrp,
            ];
        }

        // Step 2: expected priced variants — soonest expected_date wins.
        $expectedVariants = $variantsWithPrice->filter(
            fn (ProductVariant $v) => self::variantExpectedQty($v) > 0
                && self::variantEarliestExpectedDate($v) !== null
        );

        if ($expectedVariants->isNotEmpty()) {
            $firstVariant = $expectedVariants
                ->sortBy(fn (ProductVariant $v) => self::variantEarliestExpectedDate($v))
                ->first();

            $expectedQty = self::variantExpectedQty($firstVariant);
            $expectedDate = self::variantEarliestExpectedDate($firstVariant);
            $badge = ProductVariant::badgeFromQty(0, $expectedQty, $expectedDate, $threshold);

            return [
                'badge' => $badge,
                'firstVariant' => $firstVariant,
                'maxQty' => $expectedQty,
                'minQty' => $minQty,
                'step' => $step,
                'myPrice' => $firstVariant->priceFor($contractor),
                'rrp' => $rrp,
            ];
        }

        // Step 3: no orderable variant — informational price only, no action.
        $totalAvailQty = $activeVariants->sum(fn (ProductVariant $v) => self::variantAvailQty($v));
        $totalExpQty = $activeVariants->sum(fn (ProductVariant $v) => self::variantExpectedQty($v));
        $earliestExpDate = $activeVariants
            ->map(fn (ProductVariant $v) => self::variantEarliestExpectedDate($v))
            ->filter()
            ->sort()
            ->first();

        return [
            'badge' => ProductVariant::badgeFromQty($totalAvailQty, $totalExpQty, $earliestExpDate, $threshold),
            'firstVariant' => null,
            'maxQty' => 0,
            'minQty' => $minQty,
            'step' => $step,
            'myPrice' => $product->minPriceFor($contractor),
            'rrp' => $rrp,
        ];
    }

    private static function variantAvailQty(ProductVariant $variant): int
    {
        return (int) $variant->stocks->sum(fn ($s) => $s->quantity - ($s->reserved ?? 0));
    }

    private static function variantExpectedQty(ProductVariant $variant): int
    {
        return (int) ($variant->stocks->sum('expected_quantity') ?? 0);
    }

    private static function variantEarliestExpectedDate(ProductVariant $variant): ?Carbon
    {
        return $variant->stocks
            ->whereNotNull('expected_date')
            ->sortBy('expected_date')
            ->first()
            ?->expected_date;
    }
}
