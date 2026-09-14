<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeGroupResource\Pages\CreateAttributeGroup;
use App\Filament\Resources\AttributeGroupResource\Pages\EditAttributeGroup;
use App\Filament\Resources\AttributeGroupResource\Pages\ListAttributeGroups;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductTypeResource;
use App\Filament\Resources\ProductTypeResource\Pages\EditProductType;
use App\Filament\Resources\ProductTypeResource\Pages\ListProductTypes;
use App\Filament\Resources\ProductTypeResource\RelationManagers\FieldPlacementsRelationManager;
use App\Filament\Resources\ProductTypeResource\RelationManagers\GroupPlacementsRelationManager;
use App\Models\AttributeGroup;
use App\Models\Product;
use App\Models\ProductActiveOptionalGroup;
use App\Models\ProductType;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductStructure\ProductStructureMutationService;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class ProductStructureFilamentUiTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $manager;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->workspace = Workspace::query()->where('is_default', true)->sole();
        $this->manager = User::factory()->create(['is_active' => true]);
        $this->viewer = User::factory()->create(['is_active' => true]);
        $managerMembership = $this->makeWorkspaceMembership($this->workspace, $this->manager);
        $this->makeWorkspaceMembership($this->workspace, $this->viewer);
        $role = $this->createRoleWithPermissions(
            $this->workspace->id,
            'Structure Manager',
            [WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE],
        );
        $this->assignRoleToMembership($managerMembership, $role);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function manager_can_fork_basic_product_from_list_action(): void
    {
        Livewire::actingAs($this->manager)
            ->test(ListProductTypes::class)
            ->assertActionExists('forkBasic')
            ->callAction('forkBasic', data: [
                'code' => 'strollers',
                'label_uk' => 'Коляски',
                'label_en' => 'Strollers',
            ])
            ->assertNotified();

        $this->assertDatabaseHas('product_types', [
            'workspace_id' => $this->workspace->id,
            'code' => 'strollers',
            'is_default' => false,
        ]);
    }

    #[Test]
    public function basic_product_cannot_open_edit_page_or_relation_managers(): void
    {
        $basic = ProductType::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('is_default', true)
            ->sole();

        $this->actingAs($this->manager)
            ->get(ProductTypeResource::getUrl('edit', ['record' => $basic]))
            ->assertForbidden();

        $this->assertFalse(GroupPlacementsRelationManager::canViewForRecord($basic, EditProductType::class));
        $this->assertFalse(FieldPlacementsRelationManager::canViewForRecord($basic, EditProductType::class));
    }

    #[Test]
    public function custom_type_edit_page_and_relation_managers_are_available(): void
    {
        $custom = ProductType::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => 'custom_type',
            'localized_labels' => ['uk' => 'Власний тип', 'en' => 'Custom Type'],
            'status' => 'active',
            'is_default' => false,
            'structure_revision' => 1,
        ]);

        $this->actingAs($this->manager)
            ->get(ProductTypeResource::getUrl('edit', ['record' => $custom]))
            ->assertOk();

        $this->assertTrue(GroupPlacementsRelationManager::canViewForRecord($custom, EditProductType::class));
        $this->assertTrue(FieldPlacementsRelationManager::canViewForRecord($custom, EditProductType::class));
    }

    #[Test]
    public function product_type_and_attribute_group_tables_expose_no_delete_actions(): void
    {
        $productTypes = Livewire::actingAs($this->manager)->test(ListProductTypes::class);
        $attributeGroups = Livewire::actingAs($this->manager)->test(ListAttributeGroups::class);

        $this->assertSame(['edit'], array_map(
            fn ($action): string => $action->getName(),
            $productTypes->instance()->getTable()->getRecordActions(),
        ));
        $this->assertSame(['edit'], array_map(
            fn ($action): string => $action->getName(),
            $attributeGroups->instance()->getTable()->getRecordActions(),
        ));
    }

    #[Test]
    public function attribute_group_create_and_edit_use_governed_services(): void
    {
        Livewire::actingAs($this->manager)
            ->test(CreateAttributeGroup::class)
            ->fillForm([
                'code' => 'dimensions',
                'label_uk' => 'Розміри',
                'label_en' => 'Dimensions',
                'description' => 'Параметри розміру',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = AttributeGroup::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('code', 'dimensions')
            ->sole();

        Livewire::actingAs($this->manager)
            ->test(EditAttributeGroup::class, ['record' => $group->getRouteKey()])
            ->fillForm([
                'label_uk' => 'Габарити',
                'label_en' => 'Dimensions',
                'description' => 'Оновлений опис',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Габарити', $group->fresh()->localized_labels['uk']);
        $this->assertSame('Оновлений опис', $group->fresh()->description);
    }

    #[Test]
    public function user_without_manage_permission_cannot_access_structure_resources(): void
    {
        $this->actingAs($this->viewer)
            ->get(ProductTypeResource::getUrl('index'))
            ->assertForbidden();
    }

    #[Test]
    public function product_table_exposes_governed_type_change_and_optional_group_actions(): void
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'STRUCT-UI-1',
            'name' => 'Structure UI product',
            'is_active' => true,
        ]);
        $custom = app(ProductStructureMutationService::class)->createProductType(
            $this->manager,
            $this->workspace,
            'structure-ui-custom',
            ['uk' => 'Власний тип', 'en' => 'Custom type'],
        );

        Livewire::actingAs($this->manager)
            ->test(ListProducts::class)
            ->assertTableActionExists('change_product_type')
            ->callTableAction('change_product_type', $product, data: ['product_type_id' => $custom->id])
            ->assertNotified();

        $this->assertSame($custom->id, $product->fresh()->product_type_id);

        $group = AttributeGroup::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => 'structure-ui-optional',
            'localized_labels' => ['uk' => 'Додаткова'],
            'status' => 'active',
        ]);
        $freshType = $custom->fresh();
        $placement = app(ProductStructureMutationService::class)->putGroupPlacement(
            $this->manager, $this->workspace, $freshType, $group, 100, true, false, $freshType->structure_revision,
        );

        Livewire::actingAs($this->manager)
            ->test(ListProducts::class)
            ->assertTableActionExists('optional_groups')
            ->callTableAction('optional_groups', $product->fresh(), data: [
                'group_placement_id' => $placement->id,
                'active' => 1,
            ])
            ->assertNotified();

        $this->assertDatabaseHas('product_active_optional_groups', [
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'product_type_group_placement_id' => $placement->id,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function product_table_bulk_structure_actions_use_governed_services(): void
    {
        $first = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'STRUCT-BULK-1',
            'name' => 'Structure bulk 1',
            'is_active' => true,
        ]);
        $second = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'STRUCT-BULK-2',
            'name' => 'Structure bulk 2',
            'is_active' => true,
        ]);
        $structure = app(ProductStructureMutationService::class);
        $custom = $structure->createProductType(
            $this->manager,
            $this->workspace,
            'structure-bulk-custom',
            ['uk' => 'Bulk type'],
        );

        Livewire::actingAs($this->manager)
            ->test(ListProducts::class)
            ->mountTableBulkAction('assign_product_type', [$first, $second])
            ->setTableBulkActionData(['target_product_type_id' => $custom->id])
            ->callMountedTableBulkAction()
            ->assertNotified();

        $this->assertSame($custom->id, $first->fresh()->product_type_id);
        $this->assertSame($custom->id, $second->fresh()->product_type_id);

        $group = $structure->createAttributeGroup(
            $this->manager,
            $this->workspace,
            'bulk-extra-group',
            ['uk' => 'Додаткова bulk'],
        );
        $freshType = $custom->fresh();
        $placement = $structure->putGroupPlacement(
            $this->manager,
            $this->workspace,
            $freshType,
            $group,
            100,
            true,
            false,
            $freshType->structure_revision,
        );

        Livewire::actingAs($this->manager)
            ->test(ListProducts::class)
            ->mountTableBulkAction('set_optional_group_state', [$first->fresh(), $second->fresh()])
            ->setTableBulkActionData([
                'attribute_group_id' => $group->id,
                'active' => 1,
            ])
            ->callMountedTableBulkAction()
            ->assertNotified();

        $this->assertSame(2, ProductActiveOptionalGroup::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->whereIn('product_id', [$first->id, $second->id])
            ->where('product_type_group_placement_id', $placement->id)
            ->where('is_active', true)
            ->count());
    }
}
