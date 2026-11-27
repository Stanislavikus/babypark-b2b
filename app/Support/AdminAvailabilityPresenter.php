<?php

namespace App\Support;

use App\Models\Product;
use App\Services\Availability\AvailabilityResolver;
use Carbon\Carbon;

/**
 * Display/filter presenter for admin availability classification.
 *
 * Delegates net-quantity calculation to AvailabilityResolver; keeps badge/label formatting.
 */
class AdminAvailabilityPresenter
{
    public const BUCKET_IN_STOCK = 'У наявності';

    public const BUCKET_EXPECTED = 'Очікується';

    public const BUCKET_OUT_OF_STOCK = 'Немає в наявності';

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

    public static function badgeColor(string $label): string
    {
        return match (true) {
            str_starts_with($label, self::BUCKET_IN_STOCK) => 'success',
            str_starts_with($label, self::BUCKET_EXPECTED) => 'info',
            default => 'gray',
        };
    }

    /**
     * SQL subquery: net available quantity across all variants of a product.
     */
    public static function netQtySql(): string
    {
        return AvailabilityResolver::netQtySqlForProduct('products.id');
    }

    public static function earliestExpectedDateSql(): string
    {
        return '(SELECT MIN(s.expected_date)
                 FROM stocks s
                 INNER JOIN product_variants pv ON s.variant_id = pv.id
                 WHERE pv.product_id = products.id
                 AND s.expected_date IS NOT NULL)';
    }

    /**
     * @return array{0: int, 1: Carbon|null}
     */
    private static function computeNetQtyAndDate(Product $product): array
    {
        $resolver = app(AvailabilityResolver::class);
        $netQty = 0;
        $earliestExpectedDate = null;

        foreach ($product->variants as $variant) {
            $netQty += max(0, $resolver->netAvailable($variant));

            foreach ($variant->stocks as $stock) {
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
