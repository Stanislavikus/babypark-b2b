<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductVariant;
use App\Models\ProductVariantAxis;
use App\Models\User;
use App\Models\VariantFieldValue;
use App\Models\Workspace;
use App\Services\Catalog\ProductVariantStructureService;
use App\Services\ProductStructure\ProductOptionalGroupMutationService;
use App\Services\ProductStructure\ProductStructureMutationService;
use App\Services\ProductStructure\ProductTypeChangeImpactService;
use App\Services\ProductStructure\ProductTypeMutationService;
use App\Support\Catalog\Exceptions\ProductVariantStructureException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class MasterProductVariantStructureTest extends TestCase
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
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Product variant manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
            WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE,
        ]);
        $this->assignRoleToMembership($membership, $role);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function simple_promotion_preserves_existing_variant_and_creates_explicit_source_neutral_siblings(): void
    {
        [$product, $existing] = $this->manualProduct();
        $color = $this->selectVariantBinding('color_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий', 'red' => 'Червоний']);
        $existingId = $existing->id;

        $variants = app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['white', 'red'],
        );

        $this->assertCount(3, $variants);
        $this->assertSame($existingId, $variants->first()->id);
        $this->assertSame('BASE-SKU', $variants->first()->sku);
        $this->assertDatabaseHas('product_variant_axes', [
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $color->id,
            'sort_order' => 0,
        ]);
        $this->assertSame(['black', 'white', 'red'], VariantFieldValue::withoutWorkspaceScope()
            ->whereIn('variant_id', $variants->pluck('id'))
            ->where('field_binding_id', $color->id)
            ->orderBy('variant_id')
            ->pluck('value_text')
            ->all());

        foreach ($variants->slice(1) as $created) {
            $this->assertNull($created->onec_guid);
            $this->assertNull($created->sku);
            $this->assertNull($created->barcode_ean);
            $this->assertSame(0, $created->available_quantity_cache);
            $this->assertFalse($created->stocks()->exists());
            $this->assertFalse($created->prices()->exists());
        }
    }

    #[Test]
    public function second_axis_assigns_existing_variants_without_cartesian_expansion(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('color_axis_second', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);
        $size = $this->selectVariantBinding('size_axis_second', 'Розмір', ['s' => 'S', 'm' => 'M']);
        $service = app(ProductVariantStructureService::class);
        $variants = $service->promoteSimple($this->actor, $this->workspace, $product, $color->id, 'black', ['white']);

        $service->addAxis($this->actor, $this->workspace, $product, $size->id, [
            (string) $variants[0]->id => 's',
            (string) $variants[1]->id => 'm',
        ]);

        $this->assertSame(2, ProductVariantAxis::withoutWorkspaceScope()->where('product_id', $product->id)->count());
        $this->assertSame(2, ProductVariant::withoutWorkspaceScope()->where('product_id', $product->id)->count());
        $this->assertSame(['s', 'm'], VariantFieldValue::withoutWorkspaceScope()
            ->whereIn('variant_id', $variants->pluck('id'))
            ->where('field_binding_id', $size->id)
            ->orderBy('variant_id')
            ->pluck('value_text')
            ->all());
    }

    #[Test]
    public function explicit_variant_requires_complete_unique_declared_axis_combination(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('color_axis_add', 'Колір', ['black' => 'Чорний', 'white' => 'Білий', 'red' => 'Червоний']);
        $size = $this->selectVariantBinding('size_axis_add', 'Розмір', ['s' => 'S', 'm' => 'M', 'l' => 'L']);
        $service = app(ProductVariantStructureService::class);
        $variants = $service->promoteSimple($this->actor, $this->workspace, $product, $color->id, 'black', ['white']);
        $service->addAxis($this->actor, $this->workspace, $product, $size->id, [
            (string) $variants[0]->id => 's',
            (string) $variants[1]->id => 'm',
        ]);

        $created = $service->addVariant($this->actor, $this->workspace, $product, [
            $color->id => 'red',
            $size->id => 'l',
        ], 'RED-L', '1234567890123');

        $this->assertSame('RED-L', $created->sku);
        $this->assertSame('1234567890123', $created->barcode_ean);
        $this->assertDatabaseHas('variant_field_values', ['variant_id' => $created->id, 'field_binding_id' => $color->id, 'value_text' => 'red']);
        $this->assertDatabaseHas('variant_field_values', ['variant_id' => $created->id, 'field_binding_id' => $size->id, 'value_text' => 'l']);

        $this->expectException(ProductVariantStructureException::class);
        $service->addVariant($this->actor, $this->workspace, $product, [
            $color->id => 'red',
            $size->id => 'l',
        ]);
    }

    #[Test]
    public function workspace_action_promotes_simple_product_and_renders_declared_axis_table(): void
    {
        [$product, $existing] = $this->manualProduct();
        $color = $this->selectVariantBinding('ui_color_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible('promote_variants')
            ->callAction('promote_variants', [
                'axis_binding_id' => $color->id,
                'existing_value' => 'black',
                'additional_values' => ['white'],
            ])
            ->assertNotified()
            ->assertSee('Чорний')
            ->assertSee('Білий')
            ->assertSee('BASE-SKU')
            ->assertSee('Залишок');

        $this->assertSame($existing->id, ProductVariant::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->firstOrFail()
            ->id);
    }

    #[Test]
    public function workspace_actions_add_second_axis_and_one_explicit_variant_without_cartesian_expansion(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('ui_color_second', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);
        $size = $this->selectVariantBinding('ui_size_second', 'Розмір', ['s' => 'S', 'm' => 'M']);
        $service = app(ProductVariantStructureService::class);
        $variants = $service->promoteSimple($this->actor, $this->workspace, $product, $color->id, 'black', ['white']);

        $component = Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible('add_variant_axis')
            ->callAction('add_variant_axis', [
                'axis_binding_id' => $size->id,
                'assignments' => [
                    (string) $variants[0]->id => 's',
                    (string) $variants[1]->id => 'm',
                ],
            ])
            ->assertNotified()
            ->assertActionVisible('add_variant');

        $this->assertSame(2, ProductVariant::withoutWorkspaceScope()->where('product_id', $product->id)->count());

        $component
            ->callAction('add_variant', [
                'axis_values' => [
                    $color->id => 'black',
                    $size->id => 'm',
                ],
                'sku' => 'BLACK-M',
                'gtin' => '9999999999999',
            ])
            ->assertNotified()
            ->assertSee('BLACK-M');

        $this->assertSame(3, ProductVariant::withoutWorkspaceScope()->where('product_id', $product->id)->count());
    }

    #[Test]
    public function inactive_historical_variant_blocks_further_shape_expansion(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('inactive_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий', 'red' => 'Червоний']);
        $service = app(ProductVariantStructureService::class);
        $variants = $service->promoteSimple($this->actor, $this->workspace, $product, $color->id, 'black', ['white']);
        $variants[1]->forceFill(['is_active' => false])->save();

        $this->expectException(ProductVariantStructureException::class);
        $service->addVariant($this->actor, $this->workspace, $product, [$color->id => 'red']);
    }

    #[Test]
    public function source_owned_workspace_hides_manual_variant_shape_actions(): void
    {
        [$product] = $this->manualProduct(sourceOwned: true);
        $this->selectVariantBinding('ui_source_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionHidden('promote_variants')
            ->assertActionHidden('add_variant_axis')
            ->assertActionHidden('add_variant');
    }

    #[Test]
    public function declared_axis_is_not_editable_through_generic_product_field_editor(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('protected_generic_axis', 'Колір варіанта', ['black' => 'Чорний', 'white' => 'Білий']);

        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => 'variant_note',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Примітка варіанта'],
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);
        FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::ProductVariant,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 120,
            'status' => AttributeStatus::Active,
        ]);

        app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['white'],
        );

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible('edit_product_fields')
            ->mountAction('edit_product_fields')
            ->assertMountedActionModalDontSee('Колір варіанта')
            ->assertMountedActionModalSee('Примітка варіанта');
    }

    #[Test]
    public function variant_source_reference_also_blocks_manual_shape_mutation(): void
    {
        [$product, $variant] = $this->manualProduct();
        $variant->forceFill(['onec_guid' => (string) Str::uuid()])->save();
        $color = $this->selectVariantBinding('variant_source_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionHidden('promote_variants')
            ->assertActionHidden('add_variant_axis')
            ->assertActionHidden('add_variant');

        $this->expectException(ProductVariantStructureException::class);
        app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['white'],
        );
    }

    #[Test]
    public function source_owned_product_cannot_be_manually_promoted(): void
    {
        [$product] = $this->manualProduct(sourceOwned: true);
        $color = $this->selectVariantBinding('source_owned_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);

        $this->expectException(ProductVariantStructureException::class);
        app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['white'],
        );
    }

    #[Test]
    public function declared_axis_blocks_product_type_change_and_structure_removal(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('protected_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);
        app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['white'],
        );

        $structure = app(ProductStructureMutationService::class);
        $target = $structure->createProductType($this->actor, $this->workspace, 'other-type', ['uk' => 'Інший тип']);
        $impact = app(ProductTypeChangeImpactService::class)->preview($product->fresh(), $target);

        try {
            app(ProductTypeMutationService::class)->change($this->actor, $this->workspace, $product, $target, $impact);
            $this->fail('Expected declared axes to block ProductType change.');
        } catch (ProductVariantStructureException) {
            $this->assertNotSame($target->id, $product->fresh()->product_type_id);
        }

        $placement = ProductTypeFieldPlacement::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('product_type_id', $product->product_type_id)
            ->where('field_binding_id', $color->id)
            ->sole();

        $this->expectException(ProductVariantStructureException::class);
        $structure->removeFieldPlacement($this->actor, $this->workspace, $placement);
    }

    #[Test]
    public function optional_group_containing_declared_axis_cannot_be_deactivated(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('optional_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);
        $structure = app(ProductStructureMutationService::class);
        $type = ProductType::withoutWorkspaceScope()->whereKey($product->product_type_id)->sole();
        $group = $structure->createAttributeGroup($this->actor, $this->workspace, 'optional-axis-group', ['uk' => 'Опції']);
        $optional = $structure->putGroupPlacement($this->actor, $this->workspace, $type, $group, 900, true, true);
        $structure->putFieldPlacement($this->actor, $this->workspace, $type, $optional, $color, 100, false);

        app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['white'],
        );

        $this->expectException(ProductVariantStructureException::class);
        app(ProductOptionalGroupMutationService::class)->setActive(
            $this->actor,
            $this->workspace,
            $product,
            $optional,
            false,
        );
    }

    #[Test]
    public function product_type_group_containing_declared_axis_cannot_be_removed(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('group_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);
        app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['white'],
        );

        $fieldPlacement = ProductTypeFieldPlacement::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('product_type_id', $product->product_type_id)
            ->where('field_binding_id', $color->id)
            ->sole();
        $groupPlacement = $fieldPlacement->groupPlacement()->withoutGlobalScopes()->sole();

        $this->expectException(ProductVariantStructureException::class);
        app(ProductStructureMutationService::class)->removeGroupPlacement(
            $this->actor,
            $this->workspace,
            $groupPlacement,
        );
    }

    #[Test]
    public function non_select_variant_field_cannot_be_declared_as_axis(): void
    {
        [$product] = $this->manualProduct();
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => 'free_text_axis',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Довільний текст'],
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);
        $binding = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::ProductVariant,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => true,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 100,
            'status' => AttributeStatus::Active,
        ]);

        $this->assertFalse(app(ProductVariantStructureService::class)->axisCandidates($product)->has($binding->id));

        $this->expectException(ProductVariantStructureException::class);
        app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $binding->id,
            'a',
            ['b'],
        );
    }

    #[Test]
    public function declared_axis_field_cannot_be_moved_to_another_product_type_group(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('move_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);
        app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['white'],
        );

        $structure = app(ProductStructureMutationService::class);
        $type = ProductType::withoutWorkspaceScope()->whereKey($product->product_type_id)->sole();
        $group = $structure->createAttributeGroup($this->actor, $this->workspace, 'move-axis-target', ['uk' => 'Інша група']);
        $targetGroup = $structure->putGroupPlacement($this->actor, $this->workspace, $type, $group, 950, false, true);

        $this->expectException(ProductVariantStructureException::class);
        $structure->putFieldPlacement(
            $this->actor,
            $this->workspace,
            $type,
            $targetGroup,
            $color,
            100,
            false,
        );
    }

    #[Test]
    public function group_semantics_cannot_be_changed_while_it_contains_declared_axis(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('group_semantics_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);
        app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['white'],
        );

        $fieldPlacement = ProductTypeFieldPlacement::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('product_type_id', $product->product_type_id)
            ->where('field_binding_id', $color->id)
            ->sole();
        $groupPlacement = $fieldPlacement->groupPlacement()->withoutGlobalScopes()->sole();
        $group = $groupPlacement->attributeGroup()->withoutGlobalScopes()->sole();
        $type = ProductType::withoutWorkspaceScope()->whereKey($product->product_type_id)->sole();

        $this->expectException(ProductVariantStructureException::class);
        app(ProductStructureMutationService::class)->putGroupPlacement(
            $this->actor,
            $this->workspace,
            $type,
            $group,
            $groupPlacement->sort_order,
            true,
            false,
        );
    }

    #[Test]
    public function axis_row_cannot_point_at_product_from_another_workspace(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('cross_workspace_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);
        $otherWorkspace = Workspace::query()->create(['name' => 'Other workspace']);
        $otherProduct = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $otherWorkspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Other product',
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);
        ProductVariantAxis::withoutWorkspaceScope()->create([
            'workspace_id' => $product->workspace_id,
            'product_id' => $otherProduct->id,
            'field_binding_id' => $color->id,
            'sort_order' => 0,
        ]);
    }

    #[Test]
    public function declared_axis_definition_cannot_be_archived_or_have_option_contract_changed(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('definition_guard_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);
        app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['white'],
        );
        $definition = $color->fieldDefinition()->withoutGlobalScopes()->sole();

        try {
            $definition->update(['status' => AttributeStatus::Archived]);
            $this->fail('Expected declared axis to block FieldDefinition archive.');
        } catch (ProductVariantStructureException) {
            $this->assertSame(AttributeStatus::Active, $definition->fresh()->status);
        }

        $this->expectException(ProductVariantStructureException::class);
        $definition->update(['validation_rules' => ['options' => [
            ['code' => 'black', 'labels' => ['uk' => 'Чорний']],
        ]]]);
    }

    #[Test]
    public function declared_axis_binding_cannot_be_archived_or_deleted(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('binding_guard_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);
        app(ProductVariantStructureService::class)->promoteSimple(
            $this->actor,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['white'],
        );

        try {
            $color->update(['status' => AttributeStatus::Archived]);
            $this->fail('Expected declared axis to block FieldBinding archive.');
        } catch (ProductVariantStructureException) {
            $this->assertSame(AttributeStatus::Active, $color->fresh()->status);
        }

        try {
            $color->update(['visibility_settings' => ['admin' => false, 'b2b' => true, 'channels' => []]]);
            $this->fail('Expected declared axis to block admin visibility removal.');
        } catch (ProductVariantStructureException) {
            $this->assertTrue((bool) $color->fresh()->visibility_settings['admin']);
        }

        $this->expectException(ProductVariantStructureException::class);
        $color->delete();
    }

    #[Test]
    public function mutation_requires_manage_products_permission(): void
    {
        [$product] = $this->manualProduct();
        $color = $this->selectVariantBinding('unauthorized_axis', 'Колір', ['black' => 'Чорний', 'white' => 'Білий']);
        $other = User::factory()->create();
        $this->makeWorkspaceMembership($this->workspace, $other);

        $this->expectException(AuthorizationException::class);
        app(ProductVariantStructureService::class)->promoteSimple(
            $other,
            $this->workspace,
            $product,
            $color->id,
            'black',
            ['white'],
        );
    }

    /** @return array{Product,ProductVariant} */
    private function manualProduct(bool $sourceOwned = false): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => $sourceOwned ? (string) Str::uuid() : null,
            'sku' => $sourceOwned ? 'SOURCE-P' : null,
            'name' => 'Variant test product',
            'is_active' => true,
        ]);
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => $sourceOwned ? (string) Str::uuid() : null,
            'sku' => 'BASE-SKU',
            'barcode_ean' => 'BASE-GTIN',
            'attributes' => [],
            'is_active' => true,
        ]);

        return [$product, $variant];
    }

    /** @param array<string,string> $options */
    private function selectVariantBinding(string $code, string $label, array $options): FieldBinding
    {
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => $code,
            'data_type' => AttributeDataType::Select,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => $label],
            'validation_rules' => [
                'options' => collect($options)->map(fn (string $optionLabel, string $optionCode): array => [
                    'code' => $optionCode,
                    'labels' => ['uk' => $optionLabel],
                ])->values()->all(),
            ],
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        return FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::ProductVariant,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => true,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 100,
            'status' => AttributeStatus::Active,
        ]);
    }
}
