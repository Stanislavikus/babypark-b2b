<?php

namespace App\Support\ProductFields;

use App\Models\Product;
use Illuminate\Support\HtmlString;

class AdminProductMargin
{
    public static function marginUah(Product $product): ?float
    {
        $rrp = $product->maxRrp();
        $costPrice = $product->cost_price !== null ? (float) $product->cost_price : null;

        if ($costPrice === null || $rrp === null || $rrp <= 0) {
            return null;
        }

        return $rrp - $costPrice;
    }

    public static function formatted(Product $product, string $format = 'percent'): ?string
    {
        $marginUah = self::marginUah($product);

        if ($marginUah === null) {
            return null;
        }

        $rrp = $product->maxRrp();

        return $format === 'percent'
            ? number_format(($marginUah / $rrp) * 100, 1).'%'
            : number_format($marginUah, 2, '.', ' ').' ₴';
    }

    public static function isNegative(Product $product): bool
    {
        $marginUah = self::marginUah($product);

        return $marginUah !== null && $marginUah < 0;
    }

    public static function toggleLabelHtml(string $format = 'percent'): HtmlString
    {
        return MarginToggle::labelHtml($format);
    }
}
