<?php

namespace App\Support\ProductFields;

class ProductPanelVisibility
{
    public static function visibleCatalogColumns(string $panel): array
    {
        return match ($panel) {
            'cabinet' => ['photo', 'sku', 'barcode_ean', 'name', 'category', 'brand', 'stock', 'price', 'rrp', 'margin', 'order', 'url'],
            default => [],
        };
    }

    public static function visibleDetailFields(string $panel): array
    {
        return match ($panel) {
            'cabinet' => ['variants', 'url'],
            default => [],
        };
    }
}
