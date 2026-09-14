<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\AttributeGroup;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductFieldValue;
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantFieldValue;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductStructureEditorUiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function governed_editor_writes_product_and_variant_dynamic_values(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $type = ProductType::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'editor-test',
            'localized_labels' => ['uk' => 'Тестовий тип'],
            'description' => null,
            'status' => 'active',
            'is_default' => false,
            'structure_revision' => 0,
        ]);
        $group = AttributeGroup::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'editor-test-group',
            'localized_labels' => ['uk' => 'Характеристики'],
            'status' => 'active',
        ]);
        $groupPlacement = ProductTypeGroupPlacement::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_type_id' => $type->id,
            'attribute_group_id' => $group->id,
            'sort_order' => 10,
            'is_optional' => false,
            'default_active' => true,
        ]);

        $productBinding = $this->binding($workspace, 'material', FieldObjectType::Product, AttributeDataType::Text);
        $preservedBinding = $this->binding($workspace, 'preserved_note', FieldObjectType::Product, AttributeDataType::Text);
        $localizedDefinition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'localized_short_description',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Короткий опис'],
            'validation_rules' => null,
            'is_localizable' => true,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);
        $localizedBinding = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'field_definition_id' => $localizedDefinition->id,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'descriptions',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 80,
            'status' => AttributeStatus::Active,
        ]);
        $descriptionDefinition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => null,
            'code' => 'description',
            'data_type' => AttributeDataType::LongText,
            'scope' => AttributeScope::System,
            'localized_labels' => ['uk' => 'Опис'],
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);
        $descriptionBinding = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => null,
            'field_definition_id' => $descriptionDefinition->id,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Column,
            'storage_path' => 'products.description',
            'field_group' => 'descriptions',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 90,
            'status' => AttributeStatus::Active,
        ]);
        $variantBinding = $this->binding(
            $workspace,
            'variant_color',
            FieldObjectType::ProductVariant,
            AttributeDataType::Select,
            ['options' => [
                ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                ['code' => 'red', 'labels' => ['uk' => 'Червоний']],
            ]],
        );
        foreach ([$productBinding, $preservedBinding, $localizedBinding, $descriptionBinding, $variantBinding] as $index => $binding) {
            ProductTypeFieldPlacement::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'product_type_id' => $type->id,
                'product_type_group_placement_id' => $groupPlacement->id,
                'field_binding_id' => $binding->id,
                'sort_order' => ($index + 1) * 10,
                'required_for_completeness' => true,
            ]);
        }

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'EDITOR-1',
            'name' => 'Editor product',
            'is_active' => true,
        ]);
        Product::withoutWorkspaceScope()->whereKey($product->id)->update(['product_type_id' => $type->id]);
        $product->refresh();
        $variantA = $this->variant($workspace, $product, 'EDITOR-1-BLUE');
        $variantB = $this->variant($workspace, $product, 'EDITOR-1-RED');
        ProductFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $preservedBinding->id,
            'value_text' => 'KEEP-ME',
        ]);

        Livewire::actingAs($admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionExists('edit_structure_values');

        Livewire::actingAs($admin)
            ->test(ListProducts::class)
            ->assertTableActionExists('edit_structure_values')
            ->callTableAction('edit_structure_values', $product, data: [
                'product_values' => [
                    $productBinding->id => 'Cotton',
                    $localizedBinding->id => 'Локалізований опис',
                    $descriptionBinding->id => 'Governed product description',
                ],
                'variant_values' => [
                    $variantA->id => [$variantBinding->id => 'blue'],
                    $variantB->id => [$variantBinding->id => 'red'],
                ],
            ])
            ->assertNotified();

        $this->assertSame('Governed product description', $product->fresh()->description);
        $this->assertSame(
            ['uk' => 'Локалізований опис'],
            ProductFieldValue::withoutWorkspaceScope()
                ->where('product_id', $product->id)
                ->where('field_binding_id', $localizedBinding->id)
                ->sole()->value_jsonb,
        );
        $this->assertSame('Cotton', ProductFieldValue::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->where('field_binding_id', $productBinding->id)
            ->sole()->value_text);
        $this->assertSame('blue', VariantFieldValue::withoutWorkspaceScope()
            ->where('variant_id', $variantA->id)
            ->where('field_binding_id', $variantBinding->id)
            ->sole()->value_text);
        $this->assertSame('red', VariantFieldValue::withoutWorkspaceScope()
            ->where('variant_id', $variantB->id)
            ->where('field_binding_id', $variantBinding->id)
            ->sole()->value_text);
        $this->assertSame('KEEP-ME', ProductFieldValue::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->where('field_binding_id', $preservedBinding->id)
            ->sole()->value_text);
    }

    private function binding(
        Workspace $workspace,
        string $code,
        FieldObjectType $objectType,
        AttributeDataType $dataType,
        ?array $validationRules = null,
    ): FieldBinding {
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => $code,
            'data_type' => $dataType,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => Str::headline($code)],
            'validation_rules' => $validationRules,
            'is_localizable' => false,
            'is_multi_value' => $dataType === AttributeDataType::MultiSelect,
            'status' => AttributeStatus::Active,
        ]);

        return FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
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

    private function variant(Workspace $workspace, Product $product, string $sku): ProductVariant
    {
        return ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => $sku,
            'is_active' => true,
        ]);
    }
}
