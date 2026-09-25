<?php

namespace App\Support\ProductFields;

class ProductColumnVisibility
{
    public static function toggleableColumns(string $panel): array
    {
        return match ($panel) {
            'admin' => ['photo', 'barcode_ean', 'category', 'brand', 'cost_price', 'rrp', 'margin', 'url', 'merchant_type', 'tags'],
            'cabinet' => ['photo', 'barcode_ean', 'category', 'brand', 'rrp', 'url'],
            default => [],
        };
    }
}
