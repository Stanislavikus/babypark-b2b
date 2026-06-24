<?php

namespace App\Support\ProductFields;

class ProductColumnVisibility
{
    public static function toggleableColumns(string $panel): array
    {
        return match ($panel) {
            'admin', 'cabinet' => ['photo', 'category', 'brand', 'rrp', 'url'],
            default => [],
        };
    }
}
