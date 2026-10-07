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
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
    public function category_resource_allows_authorized_create_edit_and_delete(): void
    {
        $category = $this->category('Коляски');

        $this->assertTrue(CategoryResource::getCreateAuthorizationResponse()->allowed());
        $this->assertTrue(CategoryResource::getEditAuthorizationResponse($category)->allowed());
        $this->assertTrue(CategoryResource::getDeleteAuthorizationResponse($category)->allowed());
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
    public function category_writer_and_resource_reject_user_without_manage_products(): void
    {
        $unauthorized = User::factory()->create([
            'name' => 'Category Read Only',
            'role' => UserRole::Manager,
            'is_active' => true,
        ]);
        $this->makeWorkspaceMembership($this->workspace, $unauthorized);

        $category = $this->category('Protected category');

        $this->actingAs($unauthorized);

        $this->assertTrue(CategoryResource::getCreateAuthorizationResponse()->denied());
        $this->assertTrue(CategoryResource::getDeleteAuthorizationResponse($category)->denied());

        $this->expectException(AuthorizationException::class);

        app(CategoryTreeMutationService::class)->create(
            $unauthorized,
            $this->workspace,
            ['name' => 'Forbidden category'],
        );
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
    public function category_tree_migration_is_reversible(): void
    {
        $migration = require database_path('migrations/2026_10_06_100000_category_tree_management.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('categories', 'sort_order'));
        $this->assertFalse(Schema::hasColumn('categories', 'is_active'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('categories', 'sort_order'));
        $this->assertTrue(Schema::hasColumn('categories', 'is_active'));
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
    public function stale_ui_reorder_warns_and_does_not_emit_package_success(): void
    {
        $service = app(CategoryTreeMutationService::class);
        $rootA = $service->create($this->actor, $this->workspace, ['name' => 'A']);
        $rootB = $service->create($this->actor, $this->workspace, ['name' => 'B']);

        $component = Livewire::actingAs($this->actor)
            ->test(ManageCategoryTree::class);
        $stalePayload = $component->get('treeNodes');

        $service->saveTree($this->actor, $this->workspace, [
            $this->treeNode($rootB),
            $this->treeNode($rootA),
        ]);

        $component->call('saveTreeOrder', $stalePayload);

        Notification::assertNotified('Дерево не збережено');
        Notification::assertNotNotified('Збережено');

        $this->assertSame(0, $rootB->fresh()->sort_order);
        $this->assertSame(1, $rootA->fresh()->sort_order);
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

        $component = Livewire::actingAs($this->actor)
            ->test(ManageCategoryTree::class)
            ->assertSet('treeNodes.0.tree_label', 'Коляски')
            ->assertSet('treeNodes.0.children_count', 1)
            ->assertSet('treeNodes.0.products_count', 1)
            ->assertSet('treeNodes.0.stock_display_threshold', 10)
            ->assertSet('treeNodes.0.children.0.tree_label', 'Прогулянкові')
            ->assertSet('treeNodes.0.children.0.products_count', 1)
            ->assertSee('Додати категорію')
            ->assertSee('Категорія')
            ->assertSee('Підкатегорії')
            ->assertSee('Товарів')
            ->assertSee('Поріг відображення')
            ->assertSee('Стан')
            ->assertSee('Дії')
            ->assertDontSee('Згорнути все')
            ->assertDontSee('Розгорнути все')
            ->assertDontSee('Ручний порядок');

        $toolbarActions = $component->instance()->getCachedTree()->getToolbarActions();
        $nodeActionsHtml = implode('', $component->instance()->loadTreeNodeActions($root->id));

        $this->assertSame(['save'], array_map(
            fn ($action): string => $action->getName(),
            $toolbarActions,
        ));
        $this->assertStringNotContainsString('collapseAll()', $component->html());
        $this->assertStringNotContainsString('expandAll()', $component->html());
        $this->assertStringContainsString('bp-category-tree-controls', $component->html());
        $this->assertStringContainsString('bp-category-tree-search', $component->html());
        $this->assertStringContainsString('x-show="node._hasChildren"', $component->html());
        $this->assertStringContainsString('Приховати категорію', $nodeActionsHtml);
        $this->assertStringContainsString('Видалити категорію', $nodeActionsHtml);
    }

    #[Test]
    public function nonempty_category_delete_requires_exact_typed_confirmation(): void
    {
        $source = $this->category('Delete guarded');
        Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Guarded product',
            'category_id' => $source->id,
            'is_active' => true,
        ]);

        $action = TestAction::make('delete_category')->arguments([
            'tree' => true,
            'recordKey' => $source->id,
            'nodeId' => $source->id,
            'treeKey' => null,
        ]);

        Livewire::actingAs($this->actor)
            ->test(ManageCategoryTree::class)
            ->mountAction($action)
            ->assertActionDataSet(fn (array $data): bool => (
                (int) ($data['expected_products_count'] ?? -1) === 1
                && (int) ($data['expected_children_count'] ?? -1) === 0
                && (int) ($data['expected_mappings_count'] ?? -1) === 0
            ))
            ->unmountAction()
            ->callAction($action, [
                'product_destination' => '__uncategorized__',
                'confirmation' => 'delete',
            ])
            ->assertHasActionErrors(['confirmation']);

        $this->assertDatabaseHas('categories', ['id' => $source->id]);
        $this->assertDatabaseHas('products', ['category_id' => $source->id]);
    }

    #[Test]
    public function confirmed_category_delete_action_uses_governed_writer(): void
    {
        $source = $this->category('Delete confirmed');
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Product survives category delete',
            'category_id' => $source->id,
            'is_active' => true,
        ]);

        $action = TestAction::make('delete_category')->arguments([
            'tree' => true,
            'recordKey' => $source->id,
            'nodeId' => $source->id,
            'treeKey' => null,
        ]);

        Livewire::actingAs($this->actor)
            ->test(ManageCategoryTree::class)
            ->callAction($action, [
                'product_destination' => '__uncategorized__',
                'confirmation' => 'ВИДАЛИТИ',
            ])
            ->assertNotified('Категорію видалено');

        $this->assertDatabaseMissing('categories', ['id' => $source->id]);
        $this->assertNull($product->fresh()->category_id);
    }

    #[Test]
    public function category_tree_sorts_siblings_without_mutating_manual_order(): void
    {
        $rootB = $this->category('B category');
        $rootA = $this->category('A category');
        $rootB->update(['sort_order' => 0, 'stock_display_threshold' => 3]);
        $rootA->update(['sort_order' => 1, 'stock_display_threshold' => 20]);
        $this->category('A child', $rootA);

        $component = Livewire::actingAs($this->actor)
            ->test(ManageCategoryTree::class)
            ->assertSet('treeNodes.0.id', $rootB->id)
            ->assertSet('treeNodes.1.id', $rootA->id);

        $this->assertTrue($component->instance()->getCachedTree()->isDraggable());

        $component
            ->call('sortTree', 'name')
            ->assertSet('treeSortColumn', 'name')
            ->assertSet('treeSortDirection', 'asc')
            ->assertSet('treeNodes.0.id', $rootA->id)
            ->assertSet('treeNodes.1.id', $rootB->id);

        $this->assertFalse($component->instance()->getCachedTree()->isDraggable());

        $component
            ->call('sortTree', 'name')
            ->assertSet('treeSortDirection', 'desc')
            ->assertSet('treeNodes.0.id', $rootB->id)
            ->assertSet('treeNodes.1.id', $rootA->id)
            ->call('sortTree', 'name')
            ->assertSet('treeSortColumn', 'manual')
            ->assertSet('treeSortDirection', 'asc')
            ->assertSet('treeNodes.0.id', $rootB->id)
            ->assertSet('treeNodes.1.id', $rootA->id);

        $this->assertTrue($component->instance()->getCachedTree()->isDraggable());

        $component
            ->call('sortTree', 'children_count')
            ->assertSet('treeSortColumn', 'children_count')
            ->assertSet('treeSortDirection', 'asc')
            ->assertSet('treeNodes.0.id', $rootB->id)
            ->assertSet('treeNodes.1.id', $rootA->id);

        $this->assertFalse($component->instance()->getCachedTree()->isDraggable());

        $component
            ->call('sortTree', 'children_count')
            ->call('sortTree', 'children_count')
            ->assertSet('treeSortColumn', 'manual');

        $this->assertTrue($component->instance()->getCachedTree()->isDraggable());
        $this->assertSame(0, $rootB->fresh()->sort_order);
        $this->assertSame(1, $rootA->fresh()->sort_order);
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
