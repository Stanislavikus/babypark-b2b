<?php

namespace Tests\Feature;

use App\Livewire\Cabinet\Catalog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesAvailabilityFixtures;
use Tests\TestCase;

class CatalogReserveTest extends TestCase
{
    use CreatesAvailabilityFixtures;
    use RefreshDatabase;

    private function createCustomer(): Customer
    {
        return Customer::query()->create([
            'onec_guid' => (string) Str::uuid(),
            'name' => 'Catalog Customer',
            'short_name' => 'CC',
            'login' => 'catalog-'.Str::random(6),
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    public function test_catalog_reserve_uses_b2b_48_hour_ttl_not_default_15_minutes(): void
    {
        config([
            'b2b.reservation_ttl_hours' => 48,
            'availability.reservation_ttl_minutes' => 15,
        ]);

        $customer = $this->createCustomer();

        $product = Product::create([
            'workspace_id' => $this->defaultWorkspace()->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-CAT',
            'name' => 'Catalog Product',
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'workspace_id' => $this->defaultWorkspace()->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'VAR-CAT',
            'is_active' => true,
            'available_quantity_cache' => 100,
            'availability_status' => 'in_stock',
        ]);

        $before = now();

        Livewire::actingAs($customer, 'customer')
            ->test(Catalog::class)
            ->call('reserve', $variant->id, 1);

        $reservation = Reservation::query()->where('variant_id', $variant->id)->sole();
        $ttlMinutes = 48 * 60;

        $this->assertTrue($reservation->expires_at->between(
            $before->copy()->addMinutes($ttlMinutes - 2),
            $before->copy()->addMinutes($ttlMinutes + 2),
        ));
        $this->assertFalse($reservation->expires_at->lessThan($before->copy()->addMinutes(30)));
    }
}
