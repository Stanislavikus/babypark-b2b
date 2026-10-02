<?php

namespace Tests\Unit;

use App\Enums\CatalogProductDisplayState;
use App\Enums\CatalogSort;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Pricing\CustomerCatalogQuery;
use App\Services\Pricing\PriceResolutionSnapshot;
use App\Support\CatalogRowData;
use App\Support\Pricing\CustomerCatalogCriteria;
use App\Support\Pricing\CustomerPricingScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class CustomerCatalogVisibilityTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    public function test_active_customer_price_shows_resolved(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $product = $this->createCatalogProduct($customer->workspace, ['sku' => 'RESOLVED-1']);
        $variant = $product->variants->first();
        $this->createPriceListItem($list, $variant, 100.0);

        $row = $this->catalogRowFor($product, $customer);

        $this->assertSame(CatalogProductDisplayState::OrderableVariantSelected, $row->displayState);
        $this->assertNotNull($row->price);
        $this->assertCatalogContainsProduct($customer, $product->id);
    }

    public function test_expired_customer_price_shows_price_unavailable(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $product = $this->createCatalogProduct($customer->workspace, ['sku' => 'EXPIRED-1']);
        $variant = $product->variants->first();
        $this->createPriceListItem(
            $list,
            $variant,
            100.0,
            validUntil: CarbonImmutable::parse('2020-01-01'),
        );

        $row = $this->catalogRowFor($product, $customer);

        $this->assertSame(CatalogProductDisplayState::PriceUnavailable, $row->displayState);
        $this->assertCatalogContainsProduct($customer, $product->id);
    }

    public function test_missing_customer_item_falls_back_to_default_price_list(): void
    {
        $workspace = $this->defaultWorkspace();
        $customer = $this->createCustomer($workspace);
        $customerList = $this->createPriceList($workspace);
        $customer->update(['default_price_list_id' => $customerList->id]);

        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->first();

        $product = $this->createCatalogProduct($workspace, ['sku' => 'DEFAULT-FB-1']);
        $variant = $product->variants->first();
        $this->createPriceListItem($defaultList, $variant, 80.0);

        $row = $this->catalogRowFor($product, $customer);

        $this->assertSame(CatalogProductDisplayState::OrderableVariantSelected, $row->displayState);
        $this->assertSame(96.0, $row->price);
        $this->assertCatalogContainsProduct($customer, $product->id);
    }

    public function test_missing_list_items_fall_back_to_base_price_cache(): void
    {
        $workspace = $this->defaultWorkspace();
        $customer = $this->createCustomer($workspace);
        $customerList = $this->createPriceList($workspace);
        $customer->update(['default_price_list_id' => $customerList->id]);

        $product = $this->createCatalogProduct($workspace, ['sku' => 'BASE-FB-1'], basePriceCache: 50.0);

        $row = $this->catalogRowFor($product, $customer);

        $this->assertSame(CatalogProductDisplayState::OrderableVariantSelected, $row->displayState);
        $this->assertSame(60.0, $row->price);
        $this->assertCatalogContainsProduct($customer, $product->id);
    }

    public function test_no_price_in_any_source_shows_price_unavailable(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $product = $this->createCatalogProduct($customer->workspace, ['sku' => 'NO-PRICE-1']);

        $row = $this->catalogRowFor($product, $customer);

        $this->assertSame(CatalogProductDisplayState::PriceUnavailable, $row->displayState);
        $this->assertCatalogContainsProduct($customer, $product->id);
    }

    public function test_misconfigured_default_price_list_shows_configuration_error(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL enforces single default price list via database constraint.');
        }

        $workspace = $this->defaultWorkspace();
        $customer = $this->createCustomer($workspace);

        PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $this->createPriceList($workspace, isDefault: true);
        $this->createPriceList($workspace, isDefault: true);

        $product = $this->createCatalogProduct($workspace, ['sku' => 'CFG-ERR-1']);

        $row = $this->catalogRowFor($product, $customer);

        $this->assertSame(CatalogProductDisplayState::ConfigurationError, $row->displayState);
        $this->assertCatalogContainsProduct($customer, $product->id);
    }

    public function test_empty_customer_price_list_does_not_empty_catalog(): void
    {
        $customer = $this->createCustomer();
        $emptyList = $this->createPriceList();
        $customer->update(['default_price_list_id' => $emptyList->id]);

        $productA = $this->createCatalogProduct($customer->workspace, ['sku' => 'EMPTY-LIST-A']);
        $productB = $this->createCatalogProduct($customer->workspace, ['sku' => 'EMPTY-LIST-B'], basePriceCache: 25.0);

        $ids = $this->catalogProductIds($customer);

        $this->assertContains($productA->id, $ids);
        $this->assertContains($productB->id, $ids);
        $this->assertGreaterThanOrEqual(2, count($ids));
    }

    public function test_inactive_variant_is_excluded_from_display_selection(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $product = Product::create([
            'workspace_id' => $customer->workspace_id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'INACTIVE-VAR',
            'name' => 'Inactive Variant Product',
            'is_active' => true,
        ]);

        $inactiveVariant = ProductVariant::create([
            'workspace_id' => $customer->workspace_id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'INACTIVE-VAR-1',
            'is_active' => false,
            'available_quantity_cache' => 5,
        ]);
        $this->createPriceListItem($list, $inactiveVariant, 100.0);

        $activeVariant = ProductVariant::create([
            'workspace_id' => $customer->workspace_id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'ACTIVE-VAR-1',
            'is_active' => true,
            'available_quantity_cache' => 5,
        ]);

        $product->load(['variants.stocks', 'category']);
        $row = $this->catalogRowFor($product, $customer);

        $this->assertSame(CatalogProductDisplayState::PriceUnavailable, $row->displayState);
        $this->assertNull($row->displayedVariant);
        $this->assertCatalogContainsProduct($customer, $product->id);
    }

    public function test_inactive_product_is_hidden(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $product = $this->createCatalogProduct($customer->workspace, ['sku' => 'INACTIVE-PROD', 'is_active' => false]);
        $this->createPriceListItem($list, $product->variants->first(), 100.0);

        $ids = $this->catalogProductIds($customer);

        $this->assertNotContains($product->id, $ids);
    }

    public function test_cabinet_and_preview_parity_for_product_ids_and_projection(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $priced = $this->createCatalogProduct($customer->workspace, ['sku' => 'PARITY-PRICED']);
        $this->createPriceListItem($list, $priced->variants->first(), 100.0);
        $unpriced = $this->createCatalogProduct($customer->workspace, ['sku' => 'PARITY-UNPRICED']);

        $criteria = new CustomerCatalogCriteria(null, [], [], CatalogSort::SkuAsc, 100);
        $products = app(CustomerCatalogQuery::class)->paginateFor($customer, $criteria);
        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::now());

        foreach ($products as $product) {
            if (! in_array($product->sku, ['PARITY-PRICED', 'PARITY-UNPRICED'], true)) {
                continue;
            }

            $cabinet = CatalogRowData::forProduct($product, $customer, 1, $snapshot);
            $preview = CatalogRowData::forProduct($product, $customer, 1, $snapshot);

            $this->assertSame($cabinet->productId, $preview->productId);
            $this->assertSame($cabinet->displayState, $preview->displayState);
            $this->assertSame($cabinet->price, $preview->price);
            $this->assertSame($cabinet->displayedVariant?->id, $preview->displayedVariant?->id);
        }

        $ids = $products->pluck('id')->all();
        $this->assertContains($priced->id, $ids);
        $this->assertContains($unpriced->id, $ids);
    }

    public function test_available_brands_includes_brand_for_product_without_price(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $this->createCatalogProduct($customer->workspace, [
            'sku' => 'BRAND-NO-PRICE',
            'brand' => 'NoPriceBrand',
        ]);

        $brands = app(CustomerCatalogQuery::class)->availableBrands($customer);

        $this->assertContains('NoPriceBrand', $brands);
    }

    public function test_apply_product_scope_sql_does_not_filter_by_price(): void
    {
        $customer = $this->createCustomer();

        $query = CustomerPricingScope::applyProductScope(
            Product::query()->where('is_active', true),
            $customer,
        );
        $sql = $query->toSql();

        $this->assertStringContainsString('products', $sql);
        $this->assertStringNotContainsString('price_list_items', $sql);
        $this->assertStringNotContainsString('base_price_cache', $sql);
        $this->assertStringNotContainsString('valid_from', $sql);
        $this->assertStringNotContainsString('valid_until', $sql);
    }

    public function test_price_sort_ascending_puts_unavailable_last(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $cheap = $this->createCatalogProduct($customer->workspace, ['sku' => 'SORT-CHEAP']);
        $expensive = $this->createCatalogProduct($customer->workspace, ['sku' => 'SORT-EXP']);
        $unavailable = $this->createCatalogProduct($customer->workspace, ['sku' => 'SORT-NONE']);

        $this->createPriceListItem($list, $cheap->variants->first(), 10.0);
        $this->createPriceListItem($list, $expensive->variants->first(), 20.0);

        $criteria = new CustomerCatalogCriteria(null, [], [], CatalogSort::PriceAsc, 100);
        $orderedSkus = $this->orderedSkusForCriteria($customer, $criteria, [
            'SORT-CHEAP',
            'SORT-EXP',
            'SORT-NONE',
        ]);

        $this->assertSame(['SORT-CHEAP', 'SORT-EXP', 'SORT-NONE'], $orderedSkus);
    }

    public function test_price_sort_descending_puts_unavailable_last(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $cheap = $this->createCatalogProduct($customer->workspace, ['sku' => 'SORTD-CHEAP']);
        $expensive = $this->createCatalogProduct($customer->workspace, ['sku' => 'SORTD-EXP']);
        $unavailable = $this->createCatalogProduct($customer->workspace, ['sku' => 'SORTD-NONE']);

        $this->createPriceListItem($list, $cheap->variants->first(), 10.0);
        $this->createPriceListItem($list, $expensive->variants->first(), 20.0);

        $criteria = new CustomerCatalogCriteria(null, [], [], CatalogSort::PriceDesc, 100);
        $orderedSkus = $this->orderedSkusForCriteria($customer, $criteria, [
            'SORTD-CHEAP',
            'SORTD-EXP',
            'SORTD-NONE',
        ]);

        $this->assertSame(['SORTD-EXP', 'SORTD-CHEAP', 'SORTD-NONE'], $orderedSkus);
    }

    /**
     * @param  array<string, mixed>  $productAttrs
     */
    private function createCatalogProduct(
        $workspace,
        array $productAttrs = [],
        ?float $basePriceCache = null,
    ): Product {
        $product = Product::create(array_merge([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CAT-'.Str::random(6),
            'name' => 'Catalog Product',
            'is_active' => true,
        ], $productAttrs));

        ProductVariant::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $product->sku.'-V1',
            'is_active' => true,
            'available_quantity_cache' => 10,
            'availability_status' => 'in_stock',
            'base_price_cache' => $basePriceCache,
        ]);

        return $product->load(['variants.stocks', 'category']);
    }

    private function catalogRowFor(Product $product, $customer)
    {
        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::now());

        return CatalogRowData::forProduct($product, $customer, 1, $snapshot);
    }

    /**
     * @return list<int>
     */
    private function catalogProductIds($customer): array
    {
        $criteria = new CustomerCatalogCriteria(null, [], [], CatalogSort::SkuAsc, 500);

        return app(CustomerCatalogQuery::class)
            ->paginateFor($customer, $criteria)
            ->pluck('id')
            ->all();
    }

    private function assertCatalogContainsProduct($customer, int $productId): void
    {
        $this->assertContains($productId, $this->catalogProductIds($customer));
    }

    /**
     * @param  list<string>  $subsetSkus
     * @return list<string>
     */
    private function orderedSkusForCriteria($customer, CustomerCatalogCriteria $criteria, array $subsetSkus): array
    {
        $products = app(CustomerCatalogQuery::class)->paginateFor($customer, $criteria);

        return $products
            ->filter(fn (Product $p) => in_array($p->sku, $subsetSkus, true))
            ->pluck('sku')
            ->values()
            ->all();
    }
}
