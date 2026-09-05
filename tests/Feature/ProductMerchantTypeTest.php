<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMerchantTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_type_is_mass_assignable_and_persisted(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => fake()->uuid(),
            'sku' => 'MERCHANT-TYPE-001',
            'name' => 'Merchant type product',
            'merchant_type' => 'stroller',
            'is_active' => true,
        ]);

        $this->assertSame('stroller', $product->fresh()->merchant_type);

        $product->fill(['merchant_type' => 'car-seat'])->save();

        $this->assertSame('car-seat', $product->fresh()->merchant_type);
    }
}
