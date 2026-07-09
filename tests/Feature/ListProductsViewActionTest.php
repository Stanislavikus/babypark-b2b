<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ListProductsViewActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, product: Product}
     */
    private function createAdminAndProductWithStock(): array
    {
        $user = User::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin-test@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $workspace = Workspace::query()->where('is_default', true)->sole();

        $product = Product::query()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'SKU-TEST-001',
            'name' => 'Test Product For View Action',
            'is_active' => true,
        ]);

        $variant = ProductVariant::query()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'VAR-TEST-001',
            'is_active' => true,
            'available_quantity_cache' => 10,
            'availability_status' => 'in_stock',
        ]);

        $location = InventoryLocation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'WH-TEST',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
        ]);

        Stock::query()->create([
            'workspace_id' => $workspace->id,
            'variant_id' => $variant->id,
            'inventory_location_id' => $location->id,
            'quantity' => 10,
            'updated_at' => now(),
        ]);

        return [
            'user' => $user,
            'product' => $product->fresh(['variants.stocks']),
        ];
    }

    public function test_mount_table_view_action_mounts_hidden_view_action_for_row_click(): void
    {
        ['user' => $admin, 'product' => $product] = $this->createAdminAndProductWithStock();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(ListProducts::class);

        $recordKey = $component->instance()->getTableRecordKey($product);

        $component->call('mountTableAction', 'view', $recordKey);

        $component
            ->assertSet('mountedTableActions', ['view'])
            ->assertSet('mountedTableActionRecord', $recordKey)
            ->assertDispatched('open-modal', id: $component->id().'-table-action');

        $mountedAction = $component->instance()->getMountedTableAction();
        $this->assertNotNull($mountedAction);
        $this->assertSame('view', $mountedAction->getName());
    }
}
