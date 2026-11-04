<?php

namespace Tests\Unit;

use App\Enums\CatalogSort;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Pricing\CustomerCatalogQuery;
use App\Services\Pricing\PriceResolutionSnapshot;
use App\Services\Pricing\PriceResolver;
use App\Support\CatalogRowData;
use App\Support\Pricing\CustomerCatalogCriteria;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class CatalogQueryProfileTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    public function test_single_product_page_query_profile(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $variant = $this->createVariant();
        $this->createPriceListItem($list, $variant, 100.0);

        [$sqlCount, $resolverExecutions] = $this->measureCatalogPage($customer, 1);

        $this->assertLessThanOrEqual(15, $sqlCount, "1 product / 1 variant: {$sqlCount} SQL queries");
        $this->assertSame(1, $resolverExecutions, "1 product / 1 variant: {$resolverExecutions} resolver executions");
    }

    public function test_twenty_four_products_resolver_executions_scale_with_variant_count_not_n_plus_one_sql(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $variant = $this->createVariant();
        $this->createPriceListItem($list, $variant, 100.0);
        [$singleSql, $singleExecutions] = $this->measureCatalogPage($customer, 1);

        $this->seedCatalogProducts($customer->workspace_id, 23, $list, withPrice: true);

        [$pageSql, $pageExecutions] = $this->measureCatalogPage($customer, 24);

        $this->assertSame(24, $pageExecutions, "24 products: {$pageExecutions} resolver executions (expected 1 per variant)");
        $this->assertSame(1, $singleExecutions);

        $incrementalSqlPerProduct = ($pageSql - $singleSql) / 23;
        $this->assertLessThanOrEqual(
            8,
            $incrementalSqlPerProduct,
            "24 products: {$pageSql} total SQL (single={$singleSql}), ~".round($incrementalSqlPerProduct, 1).' SQL per additional product'
        );
    }

    public function test_multiple_variants_per_product_resolver_executions_match_variant_count(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        [, $oneVariantExecutions] = $this->measureProductWithVariantCount($customer, $list, 1);
        [, $threeVariantExecutions] = $this->measureProductWithVariantCount($customer, $list, 3);

        $this->assertSame(1, $oneVariantExecutions);
        $this->assertSame(3, $threeVariantExecutions, "3 variants: {$threeVariantExecutions} executions (no query-per-variant beyond resolution)");
    }

    public function test_no_price_products_use_same_resolver_execution_count_as_resolved(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $variant = $this->createVariant();
        $this->createPriceListItem($list, $variant, 100.0);
        [$resolvedSql, $resolvedExecutions] = $this->measureCatalogPage($customer, 1);

        $this->createVariant();
        [$mixedSql, $mixedExecutions] = $this->measureCatalogPage($customer, 2);

        $this->assertSame(1, $resolvedExecutions);
        $this->assertSame(2, $mixedExecutions, "2 products (1 no-price): {$mixedExecutions} executions");
        $this->assertLessThanOrEqual(
            $resolvedSql + 10,
            $mixedSql,
            "No-price product SQL (resolved={$resolvedSql}, mixed={$mixedSql}) should not diverge materially"
        );
    }

    /**
     * @return array{0: int, 1: int} [sqlCount, resolverExecutions]
     */
    private function measureCatalogPage($customer, int $expectedMinProducts): array
    {
        $criteria = new CustomerCatalogCriteria(null, [], [], CatalogSort::SkuAsc, 24);

        PriceResolver::resetStandardResolutionExecutions();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $products = app(CustomerCatalogQuery::class)->paginateFor($customer, $criteria);
        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::now());

        foreach ($products as $product) {
            CatalogRowData::forProduct($product, $customer, 1, $snapshot);
        }

        $sqlCount = count(DB::getQueryLog());
        $executions = PriceResolver::standardResolutionExecutions();

        $this->assertGreaterThanOrEqual($expectedMinProducts, $products->count());

        return [$sqlCount, $executions];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function measureProductWithVariantCount($customer, $list, int $variantCount): array
    {
        $workspaceId = $customer->workspace_id;

        Product::query()->where('workspace_id', $workspaceId)->delete();

        $product = Product::create([
            'workspace_id' => $workspaceId,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'MULTI-V-'.Str::random(4),
            'name' => 'Multi Variant',
            'is_active' => true,
        ]);

        for ($i = 0; $i < $variantCount; $i++) {
            $variant = ProductVariant::create([
                'workspace_id' => $workspaceId,
                'product_id' => $product->id,
                'onec_guid' => (string) Str::uuid(),
                'sku' => "MV-{$i}",
                'is_active' => true,
                'available_quantity_cache' => 5,
            ]);
            $this->createPriceListItem($list, $variant, 50.0 + $i);
        }

        return $this->measureCatalogPage($customer, 1);
    }

    private function seedCatalogProducts(string $workspaceId, int $count, $list, bool $withPrice): void
    {
        for ($i = 0; $i < $count; $i++) {
            $product = Product::create([
                'workspace_id' => $workspaceId,
                'onec_guid' => (string) Str::uuid(),
                'sku' => 'BULK-'.Str::random(6),
                'name' => 'Bulk Product '.$i,
                'is_active' => true,
            ]);

            $variant = ProductVariant::create([
                'workspace_id' => $workspaceId,
                'product_id' => $product->id,
                'onec_guid' => (string) Str::uuid(),
                'sku' => 'BULK-V-'.$i,
                'is_active' => true,
                'available_quantity_cache' => 5,
            ]);

            if ($withPrice) {
                $this->createPriceListItem($list, $variant, 10.0 + $i);
            }
        }
    }
}
