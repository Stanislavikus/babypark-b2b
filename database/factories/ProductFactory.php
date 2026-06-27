<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'BP-'.fake()->unique()->numberBetween(10000, 99999),
            'name' => fake()->words(3, true),
            'brand' => fake()->company(),
            'unit' => 'шт',
            'min_order_quantity' => 1,
            'order_step' => 1,
            'is_active' => true,
            'synced_at' => now(),
        ];
    }
}
