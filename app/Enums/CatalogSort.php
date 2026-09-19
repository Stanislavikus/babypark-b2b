<?php

namespace App\Enums;

enum CatalogSort: string
{
    case SkuAsc = 'sku_asc';
    case SkuDesc = 'sku_desc';
    case NameAsc = 'name_asc';
    case NameDesc = 'name_desc';
    case CategoryAsc = 'category_asc';
    case CategoryDesc = 'category_desc';
    case BrandAsc = 'brand_asc';
    case BrandDesc = 'brand_desc';
    case StockAsc = 'stock_asc';
    case StockDesc = 'stock_desc';
    case PriceAsc = 'price_asc';
    case PriceDesc = 'price_desc';
    case RrpAsc = 'rrp_asc';
    case RrpDesc = 'rrp_desc';
    case MarginAsc = 'margin_asc';
    case MarginDesc = 'margin_desc';

    public static function fromLegacy(string $sortBy, string $sortDir): self
    {
        $dir = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'asc';
        $key = $sortBy.'_'.$dir;

        return self::tryFrom($key) ?? self::SkuAsc;
    }

    /**
     * @return array{sortBy: string, sortDir: string}
     */
    public function toLegacy(): array
    {
        $lastUnderscore = strrpos($this->value, '_');
        $sortBy = substr($this->value, 0, $lastUnderscore);
        $sortDir = substr($this->value, $lastUnderscore + 1);

        return ['sortBy' => $sortBy, 'sortDir' => $sortDir];
    }
}
