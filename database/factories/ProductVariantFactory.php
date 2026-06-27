<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'BP-'.fake()->unique()->numberBetween(10000, 99999).'-V'.fake()->numberBetween(1, 9),
            'attributes' => null,
            'is_active' => true,
            'synced_at' => now(),
        ];
    }
}
