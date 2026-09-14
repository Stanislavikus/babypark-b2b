<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
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
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
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
use Illuminate\Support\Facades\DB;
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
    public function product_type_change_modal_shows_real_impact_before_apply(): void
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'STRUCT-PREVIEW-1',
            'name' => 'Structure preview product',
            'is_active' => true,
        ]);
        $custom = app(ProductStructureMutationService::class)->createProductType(
            $this->manager,
            $this->workspace,
            'structure-preview-custom',
            ['uk' => 'Тип для preview'],
        );

        $component = Livewire::actingAs($this->manager)
            ->test(ListProducts::class)
            ->mountTableAction('change_product_type', $product)
            ->set('mountedActions.0.data.product_type_id', $custom->id);

        $modal = html_entity_decode($component->getMountedActionModalHtml());
        $this->assertStringContainsString('Попередній перегляд впливу', $modal);
        $this->assertStringContainsString('Значення не видаляються', $modal);
        $this->assertNotSame($custom->id, $product->fresh()->product_type_id);
    }

    #[Test]
    public function group_configuration_rejects_stale_revision_captured_when_modal_opened(): void
    {
        $structure = app(ProductStructureMutationService::class);
        $type = $structure->createProductType($this->manager, $this->workspace, 'stale-ui-type', ['uk' => 'Stale UI']);
        $group = $structure->createAttributeGroup($this->manager, $this->workspace, 'stale-ui-group', ['uk' => 'Група']);
        $placement = $structure->putGroupPlacement(
            $this->manager,
            $this->workspace,
            $type,
            $group,
            100,
            false,
            true,
            $type->structure_revision,
        );
        $type->refresh();

        $component = Livewire::actingAs($this->manager)
            ->test(GroupPlacementsRelationManager::class, [
                'ownerRecord' => $type,
                'pageClass' => EditProductType::class,
            ])
            ->mountTableAction('configureGroup', $placement);

        $otherGroup = $structure->createAttributeGroup($this->manager, $this->workspace, 'stale-ui-other', ['uk' => 'Інша']);
        $structure->putGroupPlacement(
            $this->manager,
            $this->workspace,
            $type->fresh(),
            $otherGroup,
            200,
            false,
            true,
            $type->fresh()->structure_revision,
        );

        $component
            ->setTableActionData([
                'sort_order' => 999,
                'is_optional' => false,
                'default_active' => true,
            ])
            ->callMountedTableAction()
            ->assertNotified('Структура вже змінилася');

        $this->assertSame(100, $placement->fresh()->sort_order);
    }

    #[Test]
    public function field_picker_distinguishes_product_and_variant_bindings(): void
    {
        $structure = app(ProductStructureMutationService::class);
        $type = $structure->createProductType($this->manager, $this->workspace, 'ownership-ui-type', ['uk' => 'Ownership UI']);
        $group = $structure->createAttributeGroup($this->manager, $this->workspace, 'ownership-ui-group', ['uk' => 'Група']);
        $structure->putGroupPlacement($this->manager, $this->workspace, $type, $group, 100, false, true, $type->structure_revision);
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => 'shared_ownership_label',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Спільне поле'],
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);
        foreach ([FieldObjectType::Product, FieldObjectType::ProductVariant] as $objectType) {
            FieldBinding::withoutWorkspaceScope()->create([
                'workspace_id' => $this->workspace->id,
                'field_definition_id' => $definition->id,
                'object_type' => $objectType,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'field_group' => 'characteristics',
                'is_required' => false,
                'is_filterable' => false,
                'is_sortable' => false,
                'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                'sort_order' => 100,
                'status' => AttributeStatus::Active,
            ]);
        }

        $component = Livewire::actingAs($this->manager)
            ->test(FieldPlacementsRelationManager::class, [
                'ownerRecord' => $type->fresh(),
                'pageClass' => EditProductType::class,
            ])
            ->mountTableAction('addField');
        $modal = html_entity_decode($component->getMountedActionModalHtml());

        $this->assertStringContainsString('Спільне поле — Товар', $modal);
        $this->assertStringContainsString('Спільне поле — Варіант', $modal);
    }

    #[Test]
    public function product_table_structure_authorization_is_not_queried_per_row(): void
    {
        foreach (range(1, 12) as $index) {
            Product::withoutWorkspaceScope()->create([
                'workspace_id' => $this->workspace->id,
                'onec_guid' => Str::uuid()->toString(),
                'sku' => 'STRUCT-N1-'.$index,
                'name' => 'Structure N1 '.$index,
                'is_active' => true,
            ]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'workspace_permissions')) {
                $queries[] = $query->sql;
            }
        });

        Livewire::actingAs($this->manager)->test(ListProducts::class);

        $this->assertLessThanOrEqual(1, count($queries), implode("\n", $queries));
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
