<?php

namespace App\Services\Pricing;

use Illuminate\Support\Facades\DB;

class PricingSqlExpressions
{
    private static function rateLiteral(float $workspaceDefaultVatRate): string
    {
        return number_format($workspaceDefaultVatRate, 2, '.', '');
    }

    /**
     * SQL expression for gross price from a price_list_items row alias.
     */
    public static function grossFromListItemColumns(
        float $workspaceDefaultVatRate,
        string $priceColumn = 'pli.price',
        string $saleColumn = 'pli.sale_price',
        string $vatColumn = 'pli.vat_rate',
    ): string {
        $defaultVat = self::rateLiteral($workspaceDefaultVatRate);

        return "ROUND(COALESCE({$saleColumn}, {$priceColumn}) * (1 + COALESCE({$vatColumn}, {$defaultVat}) / 100.0), 2)";
    }

    /**
     * SQL expression for gross price from variant base_price_cache.
     */
    public static function grossFromBaseCacheColumn(
        float $workspaceDefaultVatRate,
        string $cacheColumn = 'pv.base_price_cache',
    ): string {
        $defaultVat = self::rateLiteral($workspaceDefaultVatRate);

        return "ROUND({$cacheColumn} * (1 + {$defaultVat} / 100.0), 2)";
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
     * Minimum gross customer price across active variants (price list item tier qty=1, else base cache).
     */
    public static function minGrossPriceSqlForProduct(
        string $productIdColumn,
        string $priceListId,
        float $workspaceDefaultVatRate,
    ): string {
        $quotedListId = DB::connection()->getPdo()->quote($priceListId);
        $now = DB::connection()->getPdo()->quote(now()->toDateTimeString());
        $grossItem = self::grossFromListItemColumns($workspaceDefaultVatRate);
        $grossCache = self::grossFromBaseCacheColumn($workspaceDefaultVatRate);

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
     * Maximum gross customer price across active variants.
     */
    public static function maxGrossPriceSqlForProduct(
        string $productIdColumn,
        string $priceListId,
        float $workspaceDefaultVatRate,
    ): string {
        $quotedListId = DB::connection()->getPdo()->quote($priceListId);
        $now = DB::connection()->getPdo()->quote(now()->toDateTimeString());
        $grossItem = self::grossFromListItemColumns($workspaceDefaultVatRate);
        $grossCache = self::grossFromBaseCacheColumn($workspaceDefaultVatRate);

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
     * Customer margin sort: max RRP cache minus min gross customer price.
     */
    public static function customerMarginSortSql(
        string $productIdColumn,
        string $priceListId,
        float $workspaceDefaultVatRate,
    ): string {
        $maxRrp = self::maxRrpSqlForProduct($productIdColumn);
        $minGross = self::minGrossPriceSqlForProduct($productIdColumn, $priceListId, $workspaceDefaultVatRate);

        return "COALESCE({$maxRrp}, 0) - COALESCE({$minGross}, 0)";
    }
}
