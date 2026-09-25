<?php

namespace Tests\Feature;

use App\Livewire\Cabinet\Catalog;
use App\Support\SessionCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * GAP-024 PR4: Livewire 4 component interaction for catalogue quantity and cart.
 */
class CatalogQuantityAddToCartLivewireTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    public function test_catalog_add_to_cart_uses_intended_product_quantity(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        $variant->product->update(['name' => 'LW4 Cart Product Unique']);

        $this->actingAs($customer, 'customer');

        Livewire::test(Catalog::class)
            ->set('search', 'LW4 Cart Product Unique')
            ->set('quantities', [$variant->id => 4])
            ->call('addToCart', $variant->id, 1)
            ->assertSet('flashMessage', 'Додано до кошика');

        $cart = SessionCart::all();

        $this->assertArrayHasKey($variant->id, $cart);
        $this->assertSame(4, $cart[$variant->id]['quantity']);
    }

    public function test_catalog_quantity_stays_on_intended_variant_after_search_change(): void
    {
        $customer = $this->createCustomer();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $variantA = $this->createVariant();
        $variantA->product->update(['name' => 'Alpha LW4 Product', 'sku' => 'ALPHA-LW4']);
        $this->createPriceListItem($list, $variantA, 50.00);

        $variantB = $this->createVariant();
        $variantB->product->update(['name' => 'Beta LW4 Product', 'sku' => 'BETA-LW4']);
        $this->createPriceListItem($list, $variantB, 60.00);

        $this->actingAs($customer, 'customer');

        $component = Livewire::test(Catalog::class)
            ->set('quantities', [
                $variantA->id => 3,
                $variantB->id => 7,
            ]);

        $component
            ->set('search', 'Alpha')
            ->assertSet('quantities.'.$variantA->id, 3)
            ->assertSet('quantities.'.$variantB->id, 7);

        $component
            ->set('search', 'Beta')
            ->assertSet('quantities.'.$variantA->id, 3)
            ->assertSet('quantities.'.$variantB->id, 7);
    }
}
