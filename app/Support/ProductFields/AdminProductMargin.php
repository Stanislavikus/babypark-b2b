<?php

namespace App\Support\ProductFields;

use App\Models\Product;
use App\Services\Pricing\ProductPricingSummary;
use Illuminate\Support\HtmlString;

class AdminProductMargin
{
    public static function marginUah(Product $product): ?float
    {
        $summary = app(ProductPricingSummary::class);
        $rrp = $summary->maxRrp($product);
        $costPrices = $summary->activeVariantCostPrices($product);

        if ($costPrices->isEmpty() || $rrp === null || $rrp <= 0) {
            return null;
        }

        return $rrp - $costPrices->min();
    }

    public static function formatted(Product $product, string $format = 'percent'): ?string
    {
        $marginUah = self::marginUah($product);

        if ($marginUah === null) {
            return null;
        }

        $summary = app(ProductPricingSummary::class);
        $rrp = $summary->maxRrp($product);
        $costPrices = $summary->activeVariantCostPrices($product);

        if ($costPrices->count() > 1 && $costPrices->min() !== $costPrices->max()) {
            $maxMargin = $rrp - $costPrices->min();
            $minMargin = $rrp - $costPrices->max();

            return $format === 'percent'
                ? number_format(($minMargin / $rrp) * 100, 1).'%–'.number_format(($maxMargin / $rrp) * 100, 1).'%'
                : number_format($minMargin, 2, '.', ' ').'–'.number_format($maxMargin, 2, '.', ' ').' ₴';
        }

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
