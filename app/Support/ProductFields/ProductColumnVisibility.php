<?php

namespace App\Support\ProductFields;

class ProductColumnVisibility
{
    public static function toggleableColumns(string $panel): array
    {
        return match ($panel) {
            'admin', 'cabinet' => ['photo', 'barcode_ean', 'category', 'brand', 'rrp', 'url'],
            default => [],
        };
    }
}
