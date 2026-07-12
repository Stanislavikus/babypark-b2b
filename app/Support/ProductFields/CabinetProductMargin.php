<?php

namespace App\Support\ProductFields;

use App\Models\Customer;
use App\Models\Product;
use App\Support\CatalogRowData;

class CabinetProductMargin
{
    public static function marginUah(Product $product, Customer $customer): ?float
    {
        $data = CatalogRowData::forProduct($product, $customer);
        $myPrice = $data['myPrice'];
        $rrp = $data['rrp'];

        if ($myPrice === null || $rrp === null || $rrp <= 0) {
            return null;
        }

        return $rrp - $myPrice;
    }

    public static function formatted(Product $product, Customer $customer, string $format = 'percent'): ?string
    {
        $data = CatalogRowData::forProduct($product, $customer);
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

    public static function isNegative(Product $product, Customer $customer): bool
    {
        $marginUah = self::marginUah($product, $customer);

        return $marginUah !== null && $marginUah < 0;
    }
}
