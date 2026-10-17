<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\ProductCategoryTreeOptions;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class MasterProductCategoryTreeTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->workspace = Workspace::query()->where('is_default', true)->sole();

        $this->admin = User::query()->create([
            'name' => 'Master Category Admin',
            'email' => 'master-category-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $membership = $this->makeWorkspaceMembership($this->workspace, $this->admin);
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Category product editor', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_category_options_show_full_workspace_paths_and_exclude_other_workspaces(): void
    {
        $root = $this->category('Дитячі товари');
        $child = $this->category('Коляски', $root);
        $grandchild = $this->category('Прогулянкові', $child);
        $otherRoot = $this->category('Автокрісла');

        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign category workspace',
            'is_default' => false,
        ]);

        $foreign = Category::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'name' => 'Foreign category',
            'parent_id' => null,
        ]);

        $options = app(ProductCategoryTreeOptions::class)->options((string) $this->workspace->id);

        $this->assertSame('Автокрісла', $options[$otherRoot->id]);
        $this->assertSame('Дитячі товари', $options[$root->id]);
        $this->assertSame('Дитячі товари › Коляски', $options[$child->id]);
        $this->assertSame('Дитячі товари › Коляски › Прогулянкові', $options[$grandchild->id]);
        $this->assertArrayNotHasKey($foreign->id, $options);
    }

    public function test_nested_category_is_saved_through_existing_product_category_relation(): void
    {
        $root = $this->category('Дитячі товари');
        $child = $this->category('Коляски', $root);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Manual category product',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['category_id' => $child->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($child->id, $product->fresh()->category_id);
        $this->assertSame(
            'Дитячі товари › Коляски',
            app(ProductCategoryTreeOptions::class)->label($child),
        );
    }

    public function test_product_form_rejects_new_assignment_to_inactive_branch_but_preserves_existing_assignment(): void
    {
        $root = $this->category('Inactive root');
        $child = $this->category('Active child', $root);
        $root->update(['is_active' => false]);

        $unassigned = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Unassigned product',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $unassigned->getRouteKey()])
            ->fillForm(['category_id' => $child->id])
            ->call('save')
            ->assertHasFormErrors(['category_id']);

        $this->assertNull($unassigned->fresh()->category_id);

        $assigned = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Existing inactive branch product',
            'category_id' => $child->id,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $assigned->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($child->id, $assigned->fresh()->category_id);
    }

    public function test_product_form_rejects_category_from_another_workspace(): void
    {
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign category workspace',
            'is_default' => false,
        ]);

        $foreign = Category::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'name' => 'Foreign category',
            'parent_id' => null,
        ]);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Manual category product',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['category_id' => $foreign->id])
            ->call('save')
            ->assertHasFormErrors(['category_id']);

        $this->assertNull($product->fresh()->category_id);
    }

    private function category(string $name, ?Category $parent = null): Category
    {
        return Category::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => $name,
            'parent_id' => $parent?->id,
        ]);
    }
}
