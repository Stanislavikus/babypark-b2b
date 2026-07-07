<?php

namespace App\Support;

use App\Models\Product;
use Carbon\Carbon;

/**
 * Small display/filter presenter for admin availability classification.
 *
 * Calculates net available quantity by subtracting stocks.reserved,
 * providing consistent bucket labels used in the admin table column,
 * admin infolist, and the availability filter.
 *
 * NOTE: Follow-up 1 — replace this presenter with a real AvailabilityResolver
 * domain service that unifies admin, cabinet and all filter paths.
 */
class AdminAvailabilityPresenter
{
    public const BUCKET_IN_STOCK = 'У наявності';

    public const BUCKET_EXPECTED = 'Очікується';

    public const BUCKET_OUT_OF_STOCK = 'Немає в наявності';

    /**
     * Return the availability bucket for a product (requires eager-loaded variants.stocks).
     */
    public static function bucket(Product $product): string
    {
        [$netQty, $earliestExpectedDate] = self::computeNetQtyAndDate($product);

        if ($netQty > 0) {
            return self::BUCKET_IN_STOCK;
        }

        if ($earliestExpectedDate !== null) {
            return self::BUCKET_EXPECTED;
        }

        return self::BUCKET_OUT_OF_STOCK;
    }

    /**
     * Return a detailed admin-facing label that shows net quantity where available.
     * (Requires eager-loaded variants.stocks.)
     */
    public static function adminLabel(Product $product): string
    {
        [$netQty, $earliestExpectedDate] = self::computeNetQtyAndDate($product);

        if ($netQty > 0) {
            return "У наявності: {$netQty} шт";
        }

        if ($earliestExpectedDate !== null) {
            return 'Очікується '.$earliestExpectedDate->format('d.m');
        }

        return 'Немає в наявності';
    }

    /**
     * Return the Filament badge color for a given bucket string.
     */
    public static function badgeColor(string $label): string
    {
        return match (true) {
            str_starts_with($label, self::BUCKET_IN_STOCK) => 'success',
            str_starts_with($label, self::BUCKET_EXPECTED) => 'info',
            default => 'gray',
        };
    }

    /**
     * SQL subquery: net available quantity (sum of quantity − reserved across all variants).
     * Used in filter query closures.
     */
    public static function netQtySql(): string
    {
        return '(SELECT COALESCE(SUM(s.quantity - COALESCE(s.reserved, 0)), 0)
                 FROM stocks s
                 INNER JOIN product_variants pv ON s.variant_id = pv.id
                 WHERE pv.product_id = products.id)';
    }

    /**
     * SQL subquery: earliest expected_date across all variants' stocks.
     * Used in filter query closures.
     */
    public static function earliestExpectedDateSql(): string
    {
        return '(SELECT MIN(s.expected_date)
                 FROM stocks s
                 INNER JOIN product_variants pv ON s.variant_id = pv.id
                 WHERE pv.product_id = products.id
                 AND s.expected_date IS NOT NULL)';
    }

    /**
     * Compute net quantity and earliest expected date for a product.
     * Subtracts stocks.reserved from stocks.quantity.
     *
     * @return array{0: int, 1: Carbon|null}
     */
    private static function computeNetQtyAndDate(Product $product): array
    {
        $netQty = 0;
        $earliestExpectedDate = null;

        foreach ($product->variants as $variant) {
            foreach ($variant->stocks as $stock) {
                $netQty += max(0, (int) $stock->quantity - (int) ($stock->reserved ?? 0));
                if (
                    $stock->expected_date !== null
                    && ($earliestExpectedDate === null || $stock->expected_date < $earliestExpectedDate)
                ) {
                    $earliestExpectedDate = $stock->expected_date;
                }
            }
        }

        return [$netQty, $earliestExpectedDate];
    }
}
