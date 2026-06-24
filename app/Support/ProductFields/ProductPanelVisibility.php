<?php

namespace App\Support\ProductFields;

class ProductPanelVisibility
{
    public static function visibleCatalogColumns(string $panel): array
    {
        return match ($panel) {
            'cabinet' => ['photo', 'sku', 'name', 'category', 'brand', 'stock', 'price', 'rrp', 'margin', 'quantity', 'order', 'url'],
            default => [],
        };
    }

    public static function visibleDetailFields(string $panel): array
    {
        return match ($panel) {
            'cabinet' => ['sku', 'brand', 'product_url', 'variants'],
            default => [],
        };
    }
}
