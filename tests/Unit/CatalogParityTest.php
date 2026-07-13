<?php

namespace Tests\Unit;

use App\Services\Pricing\CustomerCatalogQuery;
use App\Services\Pricing\PriceResolutionSnapshot;
use App\Support\CatalogRowData;
use App\Support\Pricing\CustomerCatalogCriteria;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class CatalogParityTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    public function test_cabinet_and_preview_parity_at_quantity_one(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.0);

        $effectiveAt = CarbonImmutable::parse('2026-07-13 12:00:00.123456');
        $criteria = CustomerCatalogCriteria::fromLegacy(null, [], [], 'sku', 'asc');
        $products = app(CustomerCatalogQuery::class)->paginateFor($customer, $criteria);

        $cabinetSnapshot = new PriceResolutionSnapshot($effectiveAt);
        $previewSnapshot = new PriceResolutionSnapshot($effectiveAt);

        foreach ($products as $product) {
            $cabinet = CatalogRowData::forProduct($product, $customer, 1, $cabinetSnapshot);
            $preview = CatalogRowData::forProduct($product, $customer, 1, $previewSnapshot);

            $this->assertParityFields($cabinet, $preview);
        }
    }

    public function test_preview_quantity_ten_uses_tier_pricing(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.0, 1);
        $this->createPriceListItem($list, $variant, 90.0, 10);

        $product = $variant->product->load(['variants.stocks', 'category']);
        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::now());
        $row = CatalogRowData::forProduct($product, $customer, 10, $snapshot);

        $this->assertSame(108.0, $row->price);
    }

    private function assertParityFields($cabinet, $preview): void
    {
        $fields = [
            'productId' => 'product_id',
            'displayedVariant' => 'displayed_variant_id',
            'displayState' => 'display_state',
            'price' => 'price',
            'currency' => 'currency',
            'priceSource' => 'price_source',
            'orderable' => 'orderable',
        ];

        $this->assertSame($cabinet->productId, $preview->productId);
        $this->assertSame($cabinet->displayedVariant?->id, $preview->displayedVariant?->id);
        $this->assertSame($cabinet->displayState, $preview->displayState);
        $this->assertSame($cabinet->price, $preview->price);
        $this->assertSame($cabinet->currency, $preview->currency);
        $this->assertSame($cabinet->priceSource, $preview->priceSource);
        $this->assertSame($cabinet->orderable, $preview->orderable);
    }
}
