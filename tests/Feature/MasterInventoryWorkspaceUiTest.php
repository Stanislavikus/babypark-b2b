<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MasterInventoryWorkspaceUiTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->workspace = $this->defaultWorkspace();
        $this->actor = User::factory()->create(['role' => UserRole::Admin]);

        $membership = $this->makeWorkspaceMembership($this->workspace, $this->actor);
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Inventory UI manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function simple_product_inventory_action_is_real_and_keeps_internal_variant_hidden(): void
    {
        [$product, $variant] = $this->productWithVariants([
            ['sku' => null, 'cache' => 0],
        ]);

        $action = TestAction::make('edit_inventory')
            ->schemaComponent('inventory_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible($action)
            ->mountAction($action)
            ->assertActionMounted($action)
            ->assertFormFieldHidden('variant_id')
            ->unmountAction()
            ->callAction($action, [
                'variant_id' => (string) $variant->id,
                'expected_quantity' => 0,
                'new_quantity' => 8,
                'reason' => 'UI count',
            ])
            ->assertNotified('Залишок оновлено');

        $variant->refresh();
        $stock = Stock::withoutWorkspaceScope()->where('variant_id', $variant->id)->sole();

        $this->assertSame(8, $stock->quantity);
        $this->assertSame(8, $variant->available_quantity_cache);
    }

    #[Test]
    public function source_owned_product_inventory_action_is_read_only(): void
    {
        [$product] = $this->productWithVariants([
            ['sku' => 'SOURCE-OWNED', 'cache' => 3],
        ]);
        $product->update(['onec_guid' => '11111111-1111-4111-8111-111111111111']);

        $action = TestAction::make('edit_inventory')
            ->schemaComponent('inventory_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible($action)
            ->assertActionDisabled($action);
    }

    #[Test]
    public function configurable_product_inventory_action_updates_only_selected_variant(): void
    {
        [$product, $first, $second] = $this->productWithVariants([
            ['sku' => 'CONF-RED', 'cache' => 0],
            ['sku' => 'CONF-BLUE', 'cache' => 0],
        ]);

        $action = TestAction::make('edit_inventory')
            ->schemaComponent('inventory_actions');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction($action, [
                'variant_id' => (string) $second->id,
                'expected_quantity' => 0,
                'new_quantity' => 5,
                'reason' => null,
            ])
            ->assertNotified('Залишок оновлено');

        $this->assertSame(0, $first->fresh()->available_quantity_cache);
        $this->assertSame(5, $second->fresh()->available_quantity_cache);
        $this->assertFalse(Stock::withoutWorkspaceScope()->where('variant_id', $first->id)->exists());
        $this->assertSame(
            5,
            Stock::withoutWorkspaceScope()->where('variant_id', $second->id)->sole()->quantity,
        );
    }

    /**
     * @param  list<array{sku:?string,cache:int}>  $variants
     * @return array<int, Product|ProductVariant>
     */
    private function productWithVariants(array $variants): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'name' => 'Inventory UI product',
            'is_active' => true,
        ]);

        $created = [];

        foreach ($variants as $spec) {
            $created[] = ProductVariant::withoutWorkspaceScope()->create([
                'workspace_id' => $this->workspace->id,
                'product_id' => $product->id,
                'onec_guid' => null,
                'sku' => $spec['sku'],
                'attributes' => [],
                'is_active' => true,
                'available_quantity_cache' => $spec['cache'],
                'availability_status' => $spec['cache'] > 0 ? 'in_stock' : 'out_of_stock',
            ]);
        }

        return [$product, ...$created];
    }
}
