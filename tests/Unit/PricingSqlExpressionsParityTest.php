<?php

namespace Tests\Unit;

use App\Services\Pricing\PricingSqlExpressions;
use App\Services\Pricing\ResolvedPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PricingSqlExpressionsParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_item_gross_sql_matches_resolved_price_with_explicit_item_vat(): void
    {
        $workspaceRate = 20.0;
        $net = 90.0;
        $itemVat = 20.0;

        $phpGross = ResolvedPrice::fromListItem(
            regularNetPrice: $net,
            salePrice: null,
            vatRate: $itemVat,
            currency: 'UAH',
            source: 'test',
            sourcePriceListId: 'list',
            sourcePriceListItemId: 'item',
        )->grossPrice;

        $sqlExpr = PricingSqlExpressions::grossFromListItemColumns($workspaceRate);
        $sqlGross = (float) DB::selectOne(
            "SELECT {$sqlExpr} AS gross FROM (SELECT ? AS price, NULL AS sale_price, ? AS vat_rate) AS pli",
            [$net, $itemVat],
        )->gross;

        $this->assertSame($phpGross, $sqlGross);
    }

    public function test_list_item_gross_sql_matches_resolved_price_with_workspace_fallback_vat(): void
    {
        $workspaceRate = 19.0;
        $net = 100.0;

        $phpGross = ResolvedPrice::fromListItem(
            regularNetPrice: $net,
            salePrice: null,
            vatRate: $workspaceRate,
            currency: 'UAH',
            source: 'test',
            sourcePriceListId: 'list',
            sourcePriceListItemId: 'item',
        )->grossPrice;

        $sqlExpr = PricingSqlExpressions::grossFromListItemColumns($workspaceRate);
        $sqlGross = (float) DB::selectOne(
            "SELECT {$sqlExpr} AS gross FROM (SELECT ? AS price, NULL AS sale_price, NULL AS vat_rate) AS pli",
            [$net],
        )->gross;

        $this->assertSame($phpGross, $sqlGross);
    }

    public function test_base_cache_gross_sql_matches_resolved_price_with_workspace_rate(): void
    {
        $workspaceRate = 19.0;
        $net = 100.0;

        $phpGross = ResolvedPrice::fromBasePriceCache($net, 'UAH', $workspaceRate)->grossPrice;

        $sqlExpr = PricingSqlExpressions::grossFromBaseCacheColumn($workspaceRate);
        $sqlGross = (float) DB::selectOne(
            "SELECT {$sqlExpr} AS gross FROM (SELECT ? AS base_price_cache) AS pv",
            [$net],
        )->gross;

        $this->assertSame($phpGross, $sqlGross);
    }
}
