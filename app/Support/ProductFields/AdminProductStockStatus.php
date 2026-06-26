<?php

namespace App\Support\ProductFields;

use App\Models\Product;
use Illuminate\Support\Carbon;

class AdminProductStockStatus
{
    /**
     * @return array{totalQty: int, earliestExpectedDate: ?Carbon}
     */
    public static function aggregate(Product $product): array
    {
        $totalQty = 0;
        $earliestExpectedDate = null;

        foreach ($product->variants as $variant) {
            foreach ($variant->stocks as $stock) {
                $totalQty += (int) $stock->quantity;
                if (
                    $stock->expected_date !== null
                    && ($earliestExpectedDate === null || $stock->expected_date < $earliestExpectedDate)
                ) {
                    $earliestExpectedDate = $stock->expected_date;
                }
            }
        }

        return [
            'totalQty' => $totalQty,
            'earliestExpectedDate' => $earliestExpectedDate,
        ];
    }

    public static function label(Product $product): string
    {
        ['totalQty' => $totalQty, 'earliestExpectedDate' => $earliestExpectedDate] = self::aggregate($product);

        if ($totalQty > 0) {
            return "У наявності: {$totalQty} шт";
        }
        if ($earliestExpectedDate) {
            return 'Очікується '.$earliestExpectedDate->format('d.m');
        }

        return 'Немає в наявності';
    }

    public static function color(Product $product): string
    {
        $label = self::label($product);

        return match (true) {
            str_starts_with($label, 'У наявності') => 'success',
            str_starts_with($label, 'Очікується') => 'info',
            default => 'gray',
        };
    }
}
