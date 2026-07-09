<?php

namespace App\Services\Pricing;

use Illuminate\Support\Facades\DB;

class PricingSqlExpressions
{
    public static function defaultVatRate(): float
    {
        return (float) config('pricing.default_vat_rate', 20);
    }

    /**
     * SQL expression for gross price from a price_list_items row alias.
     */
    public static function grossFromListItemColumns(
        string $priceColumn = 'pli.price',
        string $saleColumn = 'pli.sale_price',
        string $vatColumn = 'pli.vat_rate',
    ): string {
        $defaultVat = self::defaultVatRate();

        return "ROUND(COALESCE({$saleColumn}, {$priceColumn}) * (1 + COALESCE({$vatColumn}, {$defaultVat}) / 100), 2)";
    }

    /**
     * SQL expression for gross price from variant base_price_cache.
     */
    public static function grossFromBaseCacheColumn(string $cacheColumn = 'pv.base_price_cache'): string
    {
        $defaultVat = self::defaultVatRate();

        return "ROUND({$cacheColumn} * (1 + {$defaultVat} / 100), 2)";
    }

    public static function maxRrpSqlForProduct(string $productIdColumn = 'products.id'): string
    {
        return "(SELECT MAX(pv.recommended_retail_price_cache)
                 FROM product_variants pv
                 WHERE pv.product_id = {$productIdColumn}
                 AND pv.is_active = 1
                 AND pv.recommended_retail_price_cache IS NOT NULL)";
    }

    /**
     * Minimum gross contractor price across active variants (price list item tier qty=1, else base cache).
     */
    public static function minGrossPriceSqlForProduct(string $productIdColumn, string $priceListId): string
    {
        $quotedListId = DB::connection()->getPdo()->quote($priceListId);
        $now = DB::connection()->getPdo()->quote(now()->toDateTimeString());
        $grossItem = self::grossFromListItemColumns();
        $grossCache = self::grossFromBaseCacheColumn();

        return "(SELECT MIN(
                    COALESCE(
                        (SELECT {$grossItem}
                         FROM price_list_items pli
                         WHERE pli.product_variant_id = pv.id
                         AND pli.price_list_id = {$quotedListId}
                         AND pli.status = 'active'
                         AND pli.quantity_min <= 1
                         AND (pli.valid_from IS NULL OR pli.valid_from <= {$now})
                         AND (pli.valid_until IS NULL OR pli.valid_until >= {$now})
                         ORDER BY pli.quantity_min DESC
                         LIMIT 1),
                        CASE WHEN pv.base_price_cache IS NOT NULL THEN {$grossCache} ELSE NULL END
                    )
                )
                FROM product_variants pv
                WHERE pv.product_id = {$productIdColumn}
                AND pv.is_active = 1)";
    }

    /**
     * Maximum gross contractor price across active variants.
     */
    public static function maxGrossPriceSqlForProduct(string $productIdColumn, string $priceListId): string
    {
        $quotedListId = DB::connection()->getPdo()->quote($priceListId);
        $now = DB::connection()->getPdo()->quote(now()->toDateTimeString());
        $grossItem = self::grossFromListItemColumns();
        $grossCache = self::grossFromBaseCacheColumn();

        return "(SELECT MAX(
                    COALESCE(
                        (SELECT {$grossItem}
                         FROM price_list_items pli
                         WHERE pli.product_variant_id = pv.id
                         AND pli.price_list_id = {$quotedListId}
                         AND pli.status = 'active'
                         AND pli.quantity_min <= 1
                         AND (pli.valid_from IS NULL OR pli.valid_from <= {$now})
                         AND (pli.valid_until IS NULL OR pli.valid_until >= {$now})
                         ORDER BY pli.quantity_min DESC
                         LIMIT 1),
                        CASE WHEN pv.base_price_cache IS NOT NULL THEN {$grossCache} ELSE NULL END
                    )
                )
                FROM product_variants pv
                WHERE pv.product_id = {$productIdColumn}
                AND pv.is_active = 1)";
    }

    /**
     * Admin margin sort: max RRP cache minus min variant cost_price.
     */
    public static function adminMarginSortSql(string $productIdColumn = 'products.id'): string
    {
        $maxRrp = self::maxRrpSqlForProduct($productIdColumn);

        return "COALESCE({$maxRrp}, 0) - COALESCE(
            (SELECT MIN(pv.cost_price)
             FROM product_variants pv
             WHERE pv.product_id = {$productIdColumn}
             AND pv.is_active = 1
             AND pv.cost_price IS NOT NULL),
            0
        )";
    }

    /**
     * Contractor margin sort: max RRP cache minus min gross contractor price.
     */
    public static function contractorMarginSortSql(string $productIdColumn, string $priceListId): string
    {
        $maxRrp = self::maxRrpSqlForProduct($productIdColumn);
        $minGross = self::minGrossPriceSqlForProduct($productIdColumn, $priceListId);

        return "COALESCE({$maxRrp}, 0) - COALESCE({$minGross}, 0)";
    }
}
