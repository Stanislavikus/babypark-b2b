<?php

namespace Tests\Concerns;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Workspace;
use Illuminate\Support\Str;

trait CreatesAvailabilityFixtures
{
    protected function defaultWorkspace(): Workspace
    {
        return Workspace::query()->where('is_default', true)->sole();
    }

    /**
     * @param  array<int, array{name?: string, quantity: int, expected_date?: string|null, expected_quantity?: int|null}>  $stockRows
     */
    protected function createProductWithStocks(array $stockRows, ?int $availableCache = null): Product
    {
        $workspace = $this->defaultWorkspace();

        $product = Product::create([
            'workspace_id' => $workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'SKU-'.Str::random(8),
            'name' => 'Test Product',
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'VAR-'.Str::random(8),
            'is_active' => true,
            'available_quantity_cache' => $availableCache ?? array_sum(array_column($stockRows, 'quantity')),
            'availability_status' => ($availableCache ?? array_sum(array_column($stockRows, 'quantity'))) > 0 ? 'in_stock' : 'out_of_stock',
        ]);

        foreach ($stockRows as $index => $row) {
            $location = InventoryLocation::withoutWorkspaceScope()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'name' => $row['name'] ?? 'WH-'.$index,
                ],
                [
                    'type' => 'warehouse',
                    'is_default' => $index === 0,
                    'is_active' => true,
                ],
            );

            Stock::create([
                'workspace_id' => $workspace->id,
                'variant_id' => $variant->id,
                'inventory_location_id' => $location->id,
                'quantity' => $row['quantity'],
                'expected_date' => $row['expected_date'] ?? null,
                'expected_quantity' => $row['expected_quantity'] ?? null,
                'updated_at' => now(),
            ]);
        }

        return $product->load('variants.stocks.inventoryLocation');
    }
}
