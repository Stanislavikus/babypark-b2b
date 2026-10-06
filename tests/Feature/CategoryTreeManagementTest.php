<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\Catalog\CategoryTreeMutationException;
use App\Filament\Resources\CategoryResource;
use App\Filament\Resources\CategoryResource\Pages\ManageCategoryTree;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\CategoryHierarchyService;
use App\Services\Catalog\CategoryTreeMutationService;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class CategoryTreeManagementTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->workspace = Workspace::query()->where('is_default', true)->sole();

        $this->actor = User::query()->create([
            'name' => 'Category Tree Admin',
            'email' => 'category-tree-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $membership = $this->makeWorkspaceMembership($this->workspace, $this->actor);
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Category manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
    }

    #[Test]
    public function category_resource_allows_authorized_create_but_keeps_delete_denied(): void
    {
        $category = $this->category('Коляски');

        $this->assertTrue(CategoryResource::getCreateAuthorizationResponse()->allowed());
        $this->assertTrue(CategoryResource::getEditAuthorizationResponse($category)->allowed());
        $this->assertTrue(CategoryResource::getDeleteAuthorizationResponse($category)->denied());
    }

    #[Test]
    public function category_tree_writer_creates_reparents_and_orders_only_complete_workspace_tree(): void
    {
        $service = app(CategoryTreeMutationService::class);
        $rootA = $service->create($this->actor, $this->workspace, ['name' => 'Коляски']);
        $rootB = $service->create($this->actor, $this->workspace, ['name' => 'Автокрісла']);
        $existingRootBChild = $service->create($this->actor, $this->workspace, [
            'name' => 'Група 0+',
            'parent_id' => $rootB->id,
        ]);
        $child = $service->create($this->actor, $this->workspace, [
            'name' => 'Прогулянкові',
            'parent_id' => $rootA->id,
        ]);

        $service->update($this->actor, $this->workspace, $child, [
            'name' => $child->name,
            'parent_id' => $rootB->id,
            'is_active' => true,
            'stock_display_threshold' => 10,
        ]);

        $this->assertSame(1, $child->fresh()->sort_order);

        $service->saveTree($this->actor, $this->workspace, [
            $this->treeNode($rootB, [
                $this->treeNode($existingRootBChild),
                $this->treeNode($child),
            ]),
            $this->treeNode($rootA),
        ]);

        $this->assertDatabaseHas('categories', [
            'id' => $rootB->id,
            'parent_id' => null,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('categories', [
            'id' => $existingRootBChild->id,
            'parent_id' => $rootB->id,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('categories', [
            'id' => $child->id,
            'parent_id' => $rootB->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('categories', [
            'id' => $rootA->id,
            'parent_id' => null,
            'sort_order' => 1,
        ]);

        $this->expectException(CategoryTreeMutationException::class);
        $service->saveTree($this->actor, $this->workspace, [
            $this->treeNode($rootA->fresh()),
        ]);
    }

    #[Test]
    public function category_writer_rejects_cross_workspace_parent_and_cycles(): void
    {
        $service = app(CategoryTreeMutationService::class);
        $root = $service->create($this->actor, $this->workspace, ['name' => 'Коляски']);
        $child = $service->create($this->actor, $this->workspace, [
            'name' => 'Прогулянкові',
            'parent_id' => $root->id,
        ]);

        try {
            $service->update($this->actor, $this->workspace, $root, [
                'name' => $root->name,
                'parent_id' => $child->id,
                'is_active' => true,
                'stock_display_threshold' => 10,
            ]);

            $this->fail('Cycle must be rejected.');
        } catch (CategoryTreeMutationException $exception) {
            $this->assertSame(
                'Категорію не можна перемістити всередину самої себе або її підкатегорії.',
                $exception->getMessage(),
            );
        }

        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign Category Workspace',
            'is_default' => false,
        ]);
        $foreign = Category::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'name' => 'Foreign',
            'parent_id' => null,
        ]);

        $this->expectException(CategoryTreeMutationException::class);
        $service->update($this->actor, $this->workspace, $root, [
            'name' => $root->name,
            'parent_id' => $foreign->id,
            'is_active' => true,
            'stock_display_threshold' => 10,
        ]);
    }

    #[Test]
    public function database_rejects_cross_workspace_category_parent_even_outside_domain_writer(): void
    {
        $local = $this->category('Local');

        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign DB Guard Workspace',
            'is_default' => false,
        ]);
        $foreign = Category::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'name' => 'Foreign DB Guard Parent',
            'parent_id' => null,
        ]);

        $this->expectException(QueryException::class);

        Category::withoutWorkspaceScope()
            ->whereKey($local->id)
            ->update(['parent_id' => $foreign->id]);
    }

    #[Test]
    public function inactive_ancestor_hides_branch_and_parent_filter_expands_active_descendants(): void
    {
        $root = $this->category('Коляски');
        $child = $this->category('Прогулянкові', $root);
        $grandchild = $this->category('Компактні', $child);

        $hierarchy = app(CategoryHierarchyService::class);

        $this->assertSame(
            [$root->id, $child->id, $grandchild->id],
            $hierarchy->activeDescendantIds((string) $this->workspace->id, [$root->id]),
        );

        $root->update(['is_active' => false]);
        $hierarchy = app(CategoryHierarchyService::class);

        $this->assertSame([], $hierarchy->activeDescendantIds((string) $this->workspace->id, [$root->id]));
        $this->assertSame([], $hierarchy->selectableOptions((string) $this->workspace->id));
    }

    #[Test]
    public function effective_active_visibility_is_independent_of_query_order(): void
    {
        $root = $this->category('ZZZ active root');
        $child = $this->category('AAA inactive child', $root);
        $child->update(['is_active' => false]);

        $hierarchy = app(CategoryHierarchyService::class);

        $this->assertSame(
            [$root->id],
            $hierarchy->effectiveActiveIds((string) $this->workspace->id),
        );
        $this->assertSame(
            [$root->id],
            $hierarchy->activeDescendantIds((string) $this->workspace->id, [$root->id]),
        );
    }

    #[Test]
    public function stale_tree_payload_cannot_overwrite_a_concurrent_reorder(): void
    {
        $service = app(CategoryTreeMutationService::class);
        $rootA = $service->create($this->actor, $this->workspace, ['name' => 'A']);
        $rootB = $service->create($this->actor, $this->workspace, ['name' => 'B']);

        $stalePayload = [
            $this->treeNode($rootA),
            $this->treeNode($rootB),
        ];

        $service->saveTree($this->actor, $this->workspace, [
            $this->treeNode($rootB),
            $this->treeNode($rootA),
        ]);

        $this->expectException(CategoryTreeMutationException::class);
        $this->expectExceptionMessage(
            'Дерево категорій змінилося після відкриття сторінки. Оновіть дерево і повторіть дію.',
        );

        $service->saveTree($this->actor, $this->workspace, $stalePayload);
    }

    #[Test]
    public function category_tree_page_renders_real_hierarchy_management_shell(): void
    {
        $root = $this->category('Коляски');
        $child = $this->category('Прогулянкові', $root);

        Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Товар у підкатегорії',
            'category_id' => $child->id,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->actor)
            ->test(ManageCategoryTree::class)
            ->assertSet('treeNodes.0.tree_label', 'Коляски · товарів: 1')
            ->assertSet('treeNodes.0.children.0.tree_label', 'Прогулянкові · товарів: 1')
            ->assertSee('Додати категорію');
    }

    private function category(string $name, ?Category $parent = null): Category
    {
        return Category::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => $name,
            'parent_id' => $parent?->id,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function treeNode(Category $category, array $children = []): array
    {
        $category->refresh();

        return [
            'id' => (int) $category->id,
            'original_parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
            'original_sort_order' => (int) $category->sort_order,
            'children' => $children,
        ];
    }
}
