<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
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
use App\Models\Workspace;
use App\Services\Fields\GovernedDynamicFieldValueWriter;
use App\Services\ProductStructure\ProductCompletenessService;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class MasterProductWorkspaceFieldEditingTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->workspace = $this->defaultWorkspace();
        $this->editor = User::query()->create([
            'name' => 'Product field editor',
            'email' => 'product-field-editor@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
        $membership = $this->makeWorkspaceMembership($this->workspace, $this->editor);
        $role = $this->createRoleWithPermissions(
            $this->workspace->id,
            'Product editor',
            [WorkspacePermissions::MANAGE_PRODUCTS],
        );
        $this->assignRoleToMembership($membership, $role);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function editor_renders_and_saves_product_and_simple_variant_dynamic_fields(): void
    {
        [$product, $variant, $productBinding, $variantBinding] = $this->fixture();

        Livewire::actingAs($this->editor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible('edit_product_fields')
            ->mountAction('edit_product_fields')
            ->assertMountedActionModalSee('Матеріал')
            ->assertMountedActionModalSee('Колір')
            ->setActionData([
                'values' => [
                    'product' => [
                        $productBinding->id => 'Бавовна',
                    ],
                    'variants' => [
                        (string) $variant->id => [
                            $variantBinding->id => 'blue',
                        ],
                    ],
                ],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertDatabaseHas('product_field_values', [
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $productBinding->id,
            'value_text' => 'Бавовна',
        ]);
        $this->assertDatabaseHas('variant_field_values', [
            'workspace_id' => $this->workspace->id,
            'variant_id' => $variant->id,
            'field_binding_id' => $variantBinding->id,
            'value_text' => 'blue',
        ]);

        $completeness = app(ProductCompletenessService::class)->project($product->fresh(), 'uk');
        $this->assertSame(2, $completeness->requiredCount);
        $this->assertSame(2, $completeness->filledCount);
        $this->assertSame(100, $completeness->percentage);
    }

    #[Test]
    public function variant_fields_are_edited_in_one_sibling_variant_surface(): void
    {
        [$product, $firstVariant, , $variantBinding] = $this->fixture();
        $secondVariant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'FIELD-V-SECOND',
            'attributes' => [],
            'is_active' => true,
        ]);

        Livewire::actingAs($this->editor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction('edit_product_fields')
            ->assertMountedActionModalSee('SKU '.$firstVariant->sku)
            ->assertMountedActionModalSee('SKU '.$secondVariant->sku)
            ->setActionData([
                'values' => [
                    'variants' => [
                        (string) $firstVariant->id => [
                            $variantBinding->id => 'blue',
                        ],
                        (string) $secondVariant->id => [
                            $variantBinding->id => 'red',
                        ],
                    ],
                ],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('variant_field_values', [
            'variant_id' => $firstVariant->id,
            'field_binding_id' => $variantBinding->id,
            'value_text' => 'blue',
        ]);
        $this->assertDatabaseHas('variant_field_values', [
            'variant_id' => $secondVariant->id,
            'field_binding_id' => $variantBinding->id,
            'value_text' => 'red',
        ]);
    }

    #[Test]
    public function editor_uses_writer_compatible_typed_controls_for_supported_dynamic_product_fields(): void
    {
        [$product] = $this->fixture();
        $groupPlacement = ProductTypeGroupPlacement::withoutWorkspaceScope()
            ->where('product_type_id', $product->product_type_id)
            ->sole();

        $bindings = [
            'long_text' => $this->dynamicBinding('typed_long', 'Детальний текст', FieldObjectType::Product, AttributeDataType::LongText),
            'number' => $this->dynamicBinding('typed_number', 'Кількість', FieldObjectType::Product, AttributeDataType::Number),
            'decimal' => $this->dynamicBinding('typed_decimal', 'Коефіцієнт', FieldObjectType::Product, AttributeDataType::Decimal),
            'boolean' => $this->dynamicBinding('typed_boolean', 'Складний', FieldObjectType::Product, AttributeDataType::Boolean),
            'date' => $this->dynamicBinding('typed_date', 'Дата', FieldObjectType::Product, AttributeDataType::Date),
            'multi' => $this->dynamicBinding(
                'typed_multi',
                'Функції',
                FieldObjectType::Product,
                AttributeDataType::MultiSelect,
                ['options' => [
                    ['code' => 'foldable', 'labels' => ['uk' => 'Складаний']],
                    ['code' => 'washable', 'labels' => ['uk' => 'Можна мити']],
                ]],
            ),
            'url' => $this->dynamicBinding('typed_url', 'Посилання', FieldObjectType::Product, AttributeDataType::Url),
            'localized' => $this->dynamicBinding(
                'typed_localized',
                'Локалізований текст',
                FieldObjectType::Product,
                AttributeDataType::Text,
                isLocalizable: true,
            ),
        ];

        foreach (array_values($bindings) as $index => $binding) {
            $this->place($product->product_type_id, $groupPlacement, $binding, 200 + $index, false);
        }

        Livewire::actingAs($this->editor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction('edit_product_fields')
            ->assertMountedActionModalSee('Детальний текст')
            ->assertMountedActionModalSee('Функції')
            ->assertMountedActionModalSee('Локалізований текст')
            ->setActionData([
                'values' => [
                    'product' => [
                        $bindings['long_text']->id => 'Розширений опис',
                        $bindings['number']->id => '12',
                        $bindings['decimal']->id => '12.50',
                        $bindings['boolean']->id => true,
                        $bindings['date']->id => '2026-10-01',
                        $bindings['multi']->id => ['washable', 'foldable'],
                        $bindings['url']->id => 'https://example.test/product',
                        $bindings['localized']->id => 'Локалізоване значення',
                    ],
                ],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('product_field_values', [
            'product_id' => $product->id,
            'field_binding_id' => $bindings['long_text']->id,
            'value_text' => 'Розширений опис',
        ]);
        $this->assertDatabaseHas('product_field_values', [
            'product_id' => $product->id,
            'field_binding_id' => $bindings['number']->id,
            'value_num' => '12.000000',
        ]);
        $this->assertDatabaseHas('product_field_values', [
            'product_id' => $product->id,
            'field_binding_id' => $bindings['decimal']->id,
            'value_num' => '12.500000',
        ]);
        $this->assertDatabaseHas('product_field_values', [
            'product_id' => $product->id,
            'field_binding_id' => $bindings['boolean']->id,
            'value_num' => '1.000000',
        ]);
        $this->assertDatabaseHas('product_field_values', [
            'product_id' => $product->id,
            'field_binding_id' => $bindings['date']->id,
            'value_text' => '2026-10-01',
        ]);
        $this->assertDatabaseHas('product_field_values', [
            'product_id' => $product->id,
            'field_binding_id' => $bindings['url']->id,
            'value_text' => 'https://example.test/product',
        ]);

        $multi = ProductFieldValue::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->where('field_binding_id', $bindings['multi']->id)
            ->sole();
        $this->assertSame(['foldable', 'washable'], $multi->value_jsonb);

        $localized = ProductFieldValue::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->where('field_binding_id', $bindings['localized']->id)
            ->sole();
        $this->assertSame(
            'Локалізоване значення',
            $localized->value_jsonb[app()->getLocale()] ?? null,
        );
    }

    #[Test]
    public function stale_dynamic_value_rejects_the_whole_characteristic_batch_without_partial_write(): void
    {
        [$product, , $firstBinding] = $this->fixture();
        $secondBinding = $this->dynamicBinding(
            'workspace_pattern',
            'Візерунок',
            FieldObjectType::Product,
            AttributeDataType::Text,
        );
        $groupPlacement = ProductTypeGroupPlacement::withoutWorkspaceScope()
            ->where('product_type_id', $product->product_type_id)
            ->sole();
        $this->place($product->product_type_id, $groupPlacement, $secondBinding, 200, true);

        $component = Livewire::actingAs($this->editor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction('edit_product_fields');

        app(GovernedDynamicFieldValueWriter::class)->set(
            workspaceId: $this->workspace->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
            fieldBindingId: $secondBinding->id,
            value: 'Зовнішня зміна',
        );

        $component
            ->setActionData([
                'values' => [
                    'product' => [
                        $firstBinding->id => 'Нове значення',
                        $secondBinding->id => 'Моє значення',
                    ],
                ],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertDatabaseMissing('product_field_values', [
            'product_id' => $product->id,
            'field_binding_id' => $firstBinding->id,
        ]);
        $this->assertDatabaseHas('product_field_values', [
            'product_id' => $product->id,
            'field_binding_id' => $secondBinding->id,
            'value_text' => 'Зовнішня зміна',
        ]);
    }

    #[Test]
    public function editor_can_clear_an_existing_dynamic_value_with_compare_and_set_semantics(): void
    {
        [$product, , $productBinding] = $this->fixture();
        app(GovernedDynamicFieldValueWriter::class)->set(
            workspaceId: $this->workspace->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
            fieldBindingId: $productBinding->id,
            value: 'Вовна',
        );

        Livewire::actingAs($this->editor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction('edit_product_fields')
            ->setActionData([
                'values' => [
                    'product' => [
                        $productBinding->id => '',
                    ],
                ],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('product_field_values', [
            'product_id' => $product->id,
            'field_binding_id' => $productBinding->id,
        ]);
    }

    #[Test]
    public function product_editor_without_manage_products_cannot_use_characteristic_mutation_action(): void
    {
        [$product] = $this->fixture();
        $viewer = User::query()->create([
            'name' => 'Read only product viewer',
            'email' => 'product-field-viewer@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Livewire::actingAs($viewer)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionHidden('edit_product_fields');
    }

    /** @return array{Product, ProductVariant, FieldBinding, FieldBinding} */
    private function fixture(): array
    {
        $type = ProductType::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => 'workspace-editable-type-'.Str::lower(Str::random(6)),
            'localized_labels' => ['uk' => 'Редагований тип'],
            'status' => 'active',
            'is_default' => false,
            'structure_revision' => 1,
        ]);
        $group = AttributeGroup::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => 'workspace-editable-group-'.Str::lower(Str::random(6)),
            'localized_labels' => ['uk' => 'Характеристики'],
            'status' => 'active',
        ]);
        $groupPlacement = ProductTypeGroupPlacement::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_type_id' => $type->id,
            'attribute_group_id' => $group->id,
            'sort_order' => 100,
            'is_optional' => false,
            'default_active' => true,
        ]);
        $productBinding = $this->dynamicBinding(
            'workspace_material',
            'Матеріал',
            FieldObjectType::Product,
            AttributeDataType::Text,
        );
        $variantBinding = $this->dynamicBinding(
            'workspace_color',
            'Колір',
            FieldObjectType::ProductVariant,
            AttributeDataType::Select,
            ['options' => [
                ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                ['code' => 'red', 'labels' => ['uk' => 'Червоний']],
            ]],
        );
        $this->place($type->id, $groupPlacement, $productBinding, 100, true);
        $this->place($type->id, $groupPlacement, $variantBinding, 110, true);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'FIELD-'.Str::upper(Str::random(8)),
            'name' => 'Field editor product',
            'is_active' => true,
        ]);
        $product->forceFill(['product_type_id' => $type->id])->save();
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'FIELD-V-'.Str::upper(Str::random(8)),
            'attributes' => [],
            'is_active' => true,
        ]);

        return [$product, $variant, $productBinding, $variantBinding];
    }

    private function dynamicBinding(
        string $code,
        string $label,
        FieldObjectType $objectType,
        AttributeDataType $dataType,
        ?array $validationRules = null,
        bool $isLocalizable = false,
    ): FieldBinding {
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'code' => $code.'-'.Str::lower(Str::random(6)),
            'data_type' => $dataType,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => $label],
            'validation_rules' => $validationRules,
            'is_localizable' => $isLocalizable,
            'is_multi_value' => $dataType === AttributeDataType::MultiSelect,
            'status' => AttributeStatus::Active,
        ]);

        return FieldBinding::withoutWorkspaceScope()->create([
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

    private function place(
        string $productTypeId,
        ProductTypeGroupPlacement $groupPlacement,
        FieldBinding $binding,
        int $sortOrder,
        bool $required,
    ): void {
        ProductTypeFieldPlacement::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_type_id' => $productTypeId,
            'product_type_group_placement_id' => $groupPlacement->id,
            'field_binding_id' => $binding->id,
            'sort_order' => $sortOrder,
            'required_for_completeness' => $required,
        ]);
    }
}
