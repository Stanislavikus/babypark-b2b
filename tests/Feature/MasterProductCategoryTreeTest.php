<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\ProductCategoryTreeOptions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MasterProductCategoryTreeTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->where('is_default', true)->sole();

        $this->admin = User::query()->create([
            'name' => 'Master Category Admin',
            'email' => 'master-category-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

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
