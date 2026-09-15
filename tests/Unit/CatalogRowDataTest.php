<?php

namespace Tests\Unit;

use App\Enums\CatalogProductDisplayState;
use App\Models\InventoryLocation;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Workspace;
use App\Services\Pricing\PriceResolutionSnapshot;
use App\Support\CatalogRowData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class CatalogRowDataTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    public function test_configuration_error_variant_does_not_win_over_resolved_variant(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL enforces single default price list via database constraint.');
        }

        $workspace = $this->defaultWorkspace();
        $customer = $this->createCustomer($workspace);
        $customerList = $this->createPriceList();
        $customer->update(['default_price_list_id' => $customerList->id]);

        PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $this->createPriceList($workspace, isDefault: true);
        $this->createPriceList($workspace, isDefault: true);

        $product = $this->createProductWithVariants($workspace, $customerList, [
            ['price' => 100.0, 'stock' => 5],
            ['price' => null, 'stock' => 5],
        ]);

        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::now());
        $row = CatalogRowData::forProduct($product, $customer, 1, $snapshot);

        $this->assertSame(CatalogProductDisplayState::OrderableVariantSelected, $row->displayState);
        $this->assertNotNull($row->price);
        $this->assertSame(120.0, $row->price);
    }

    public function test_informational_price_tie_breaks_by_variant_id(): void
    {
        $workspace = $this->defaultWorkspace();
        $customer = $this->createCustomer($workspace);
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $product = Product::create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'TIE-SKU',
            'name' => 'Tie Product',
            'is_active' => true,
        ]);

        $variantA = ProductVariant::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'TIE-A',
            'is_active' => true,
        ]);
        $variantB = ProductVariant::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'TIE-B',
            'is_active' => true,
        ]);

        if ($variantA->id > $variantB->id) {
            [$variantA, $variantB] = [$variantB, $variantA];
        }

        $this->createPriceListItem($list, $variantA, 100.0);
        $this->createPriceListItem($list, $variantB, 100.0);

        $product->load(['variants.stocks', 'category']);
        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::now());
        $row = CatalogRowData::forProduct($product, $customer, 1, $snapshot);

        $this->assertSame(CatalogProductDisplayState::InformationalPriceOnly, $row->displayState);
        $this->assertSame($variantA->id, $row->priceSourceVariant?->id);
        $this->assertSame(120.0, $row->price);
    }

    public function test_cheaper_variant_wins_over_lower_id(): void
    {
        $workspace = $this->defaultWorkspace();
        $customer = $this->createCustomer($workspace);
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $product = Product::create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CHEAP-SKU',
            'name' => 'Cheap Product',
            'is_active' => true,
        ]);

        $expensive = ProductVariant::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'EXP',
            'is_active' => true,
            'available_quantity_cache' => 10,
        ]);
        $cheap = ProductVariant::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CHEAP',
            'is_active' => true,
            'available_quantity_cache' => 10,
        ]);

        $this->createPriceListItem($list, $expensive, 120.0);
        $this->createPriceListItem($list, $cheap, 100.0);

        $this->createStock($workspace, $expensive, 5);
        $this->createStock($workspace, $cheap, 5);

        $product->load(['variants.stocks', 'category']);
        $row = CatalogRowData::forProduct($product, $customer);

        $this->assertSame($cheap->id, $row->displayedVariant?->id);
        $this->assertSame(120.0, $row->price);
    }

    public function test_configuration_error_picks_min_variant_id(): void
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

        $product = Product::create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-SKU',
            'name' => 'Config Product',
            'is_active' => true,
        ]);

        $variant20 = ProductVariant::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-20',
            'is_active' => true,
        ]);
        $variant10 = ProductVariant::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-10',
            'is_active' => true,
        ]);

        $product->setRelation('variants', collect([$variant20, $variant10]));
        $product->load('category');

        $row = CatalogRowData::forProduct($product, $customer);

        $this->assertSame(CatalogProductDisplayState::ConfigurationError, $row->displayState);
        $this->assertSame(min($variant10->id, $variant20->id), $row->priceSourceVariant?->id);
    }

    /**
     * @param  list<array{price: ?float, stock: int}>  $variants
     */
    private function createProductWithVariants($workspace, $customerList, array $variants): Product
    {
        $product = Product::create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'MIX-'.Str::random(4),
            'name' => 'Mixed Product',
            'is_active' => true,
        ]);

        foreach ($variants as $index => $spec) {
            $variant = ProductVariant::create([
                'workspace_id' => $workspace->id,
                'product_id' => $product->id,
                'onec_guid' => (string) Str::uuid(),
                'sku' => 'MIX-V'.$index,
                'is_active' => true,
                'available_quantity_cache' => $spec['stock'],
            ]);

            if ($spec['price'] !== null) {
                $this->createPriceListItem($customerList, $variant, $spec['price']);
            }

            $this->createStock($workspace, $variant, $spec['stock']);
        }

        return $product->load(['variants.stocks', 'category']);
    }

    private function createStock(Workspace $workspace, ProductVariant $variant, int $quantity): Stock
    {
        $location = InventoryLocation::withoutWorkspaceScope()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'name' => 'Main',
            ],
            [
                'type' => 'warehouse',
                'is_default' => true,
                'is_active' => true,
            ],
        );

        return Stock::create([
            'workspace_id' => $workspace->id,
            'variant_id' => $variant->id,
            'inventory_location_id' => $location->id,
            'quantity' => $quantity,
            'updated_at' => now(),
        ]);
    }
}
