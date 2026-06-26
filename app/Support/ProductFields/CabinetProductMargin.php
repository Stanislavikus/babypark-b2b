<?php

namespace App\Support\ProductFields;

use App\Models\Contractor;
use App\Models\Product;
use App\Support\CatalogRowData;

class CabinetProductMargin
{
    public static function marginUah(Product $product, Contractor $contractor): ?float
    {
        $data = CatalogRowData::forProduct($product, $contractor);
        $myPrice = $data['myPrice'];
        $rrp = $data['rrp'];

        if ($myPrice === null || $rrp === null || $rrp <= 0) {
            return null;
        }

        return $rrp - $myPrice;
    }

    public static function formatted(Product $product, Contractor $contractor, string $format = 'percent'): ?string
    {
        $data = CatalogRowData::forProduct($product, $contractor);
        $myPrice = $data['myPrice'];
        $rrp = $data['rrp'];

        if ($myPrice === null || $rrp === null || $rrp <= 0) {
            return null;
        }

        $marginUah = $rrp - $myPrice;

        return $format === 'percent'
            ? number_format(($marginUah / $rrp) * 100, 1).'%'
            : number_format($marginUah, 2, '.', ' ').' ₴';
    }

    public static function isNegative(Product $product, Contractor $contractor): bool
    {
        $marginUah = self::marginUah($product, $contractor);

        return $marginUah !== null && $marginUah < 0;
    }
}
