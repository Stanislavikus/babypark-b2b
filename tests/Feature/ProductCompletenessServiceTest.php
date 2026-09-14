<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\AttributeGroup;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductActiveOptionalGroup;
use App\Models\ProductFieldValue;
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use App\Models\Workspace;
use App\Services\ProductStructure\ProductCompletenessService;
use Database\Seeders\FieldDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductCompletenessServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function required_product_binding_tracks_missing_and_present_dynamic_value(): void
    {
        [$workspace, $type, $product, $group] = $this->fixture();
        $binding = $this->dynamicBinding($workspace, 'material', FieldObjectType::Product);
        $this->place($workspace, $type, $group, $binding, required: true);
        $outOfType = $this->dynamicBinding($workspace, 'legacy_only', FieldObjectType::Product);
        ProductFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $outOfType->id,
            'value_text' => 'preserved',
        ]);

        $missing = app(ProductCompletenessService::class)->project($product, 'uk');
        $this->assertSame(1, $missing->requiredCount);
        $this->assertSame(0, $missing->filledCount);
        $this->assertSame(0, $missing->percentage);
        $this->assertSame([$binding->id], $missing->missingProductBindingIds);

        ProductFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'cotton',
        ]);

        $complete = app(ProductCompletenessService::class)->project($product, 'uk');
        $this->assertSame(1, $complete->requiredCount);
        $this->assertSame(1, $complete->filledCount);
        $this->assertSame(100, $complete->percentage);
        $this->assertSame([], $complete->missingProductBindingIds);
    }

    #[Test]
    public function variant_requirement_is_complete_only_when_every_active_sibling_is_valid(): void
    {
        [$workspace, $type, $product, $group] = $this->fixture();
        $binding = $this->dynamicBinding($workspace, 'variant_color', FieldObjectType::ProductVariant);
        $this->place($workspace, $type, $group, $binding, required: true);
        $first = $this->variant($workspace, $product, true);
        $second = $this->variant($workspace, $product, true);
        $inactive = $this->variant($workspace, $product, false);

        foreach ([$first, $inactive] as $variant) {
            VariantFieldValue::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'variant_id' => $variant->id,
                'field_binding_id' => $binding->id,
                'value_text' => 'red',
            ]);
        }

        $projection = app(ProductCompletenessService::class)->project($product, 'uk');
        $this->assertSame(1, $projection->requiredCount);
        $this->assertSame(0, $projection->filledCount);
        $this->assertSame([
            ['variant_id' => $second->id, 'field_binding_id' => $binding->id],
        ], $projection->missingVariantCells);

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'variant_id' => $second->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'blue',
        ]);

        $complete = app(ProductCompletenessService::class)->project($product, 'uk');
        $this->assertSame(1, $complete->filledCount);
        $this->assertSame([], $complete->missingVariantCells);
    }

    #[Test]
    public function variant_requirement_with_zero_active_variants_is_not_applicable(): void
    {
        [$workspace, $type, $product, $group] = $this->fixture();
        $binding = $this->dynamicBinding($workspace, 'variant_size', FieldObjectType::ProductVariant);
        $this->place($workspace, $type, $group, $binding, required: true);
        $this->variant($workspace, $product, false);

        $projection = app(ProductCompletenessService::class)->project($product, 'uk');

        $this->assertSame(0, $projection->requiredCount);
        $this->assertSame(0, $projection->filledCount);
        $this->assertSame(100, $projection->percentage);
        $this->assertSame([], $projection->missingVariantCells);
    }

    #[Test]
    public function inactive_optional_group_is_excluded_until_activated(): void
    {
        [$workspace, $type, $product] = $this->fixture(includeGroup: false);
        $optionalGroup = $this->groupPlacement($workspace, $type, 'optional_specs', true, false);
        $binding = $this->dynamicBinding($workspace, 'optional_material', FieldObjectType::Product);
        $this->place($workspace, $type, $optionalGroup, $binding, required: true);

        $inactive = app(ProductCompletenessService::class)->project($product, 'uk');
        $this->assertSame(0, $inactive->requiredCount);
        $this->assertSame([$optionalGroup->id], $inactive->inactiveOptionalGroupPlacementIds);
        $this->assertSame([], $inactive->activeOptionalGroupPlacementIds);

        ProductActiveOptionalGroup::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'product_type_group_placement_id' => $optionalGroup->id,
            'is_active' => true,
        ]);

        $active = app(ProductCompletenessService::class)->project($product, 'uk');
        $this->assertSame(1, $active->requiredCount);
        $this->assertSame([$binding->id], $active->missingProductBindingIds);
        $this->assertSame([$optionalGroup->id], $active->activeOptionalGroupPlacementIds);
        $this->assertSame([], $active->inactiveOptionalGroupPlacementIds);
    }

    #[Test]
    public function select_multiselect_and_localized_values_reuse_writer_validity_semantics(): void
    {
        [$workspace, $type, $product, $group] = $this->fixture();
        $select = $this->dynamicBinding($workspace, 'color', FieldObjectType::Product, AttributeDataType::Select, false, false, ['options' => [
            ['code' => 'red'], ['code' => 'blue'],
        ]]);
        $multi = $this->dynamicBinding($workspace, 'features', FieldObjectType::Product, AttributeDataType::MultiSelect, false, true, ['options' => [
            ['code' => 'washable'], ['code' => 'foldable'],
        ]]);
        $localized = $this->dynamicBinding($workspace, 'marketing_copy', FieldObjectType::Product, AttributeDataType::Text, true);
        foreach ([$select, $multi, $localized] as $binding) {
            $this->place($workspace, $type, $group, $binding, required: true);
        }
        ProductFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $select->id,
            'value_text' => 'green',
        ]);
        ProductFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $multi->id,
            'value_jsonb' => ['washable', 'washable'],
        ]);
        ProductFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $localized->id,
            'value_jsonb' => ['en' => 'Hello'],
        ]);

        $invalid = app(ProductCompletenessService::class)->project($product, 'uk');
        $this->assertSame(3, $invalid->requiredCount);
        $this->assertSame(0, $invalid->filledCount);
        $this->assertCount(3, $invalid->missingProductBindingIds);

        ProductFieldValue::withoutWorkspaceScope()->where('product_id', $product->id)->where('field_binding_id', $select->id)->update(['value_text' => 'red']);
        ProductFieldValue::withoutWorkspaceScope()->where('product_id', $product->id)->where('field_binding_id', $multi->id)->update(['value_jsonb' => json_encode(['foldable', 'washable'], JSON_THROW_ON_ERROR)]);
        ProductFieldValue::withoutWorkspaceScope()->where('product_id', $product->id)->where('field_binding_id', $localized->id)->update(['value_jsonb' => json_encode(['en' => 'Hello', 'uk' => 'Привіт'], JSON_THROW_ON_ERROR)]);

        $valid = app(ProductCompletenessService::class)->project($product, 'uk');
        $this->assertSame(3, $valid->filledCount);
        $this->assertSame(100, $valid->percentage);
        $this->assertSame([], $valid->missingProductBindingIds);
    }

    #[Test]
    public function canonical_product_column_binding_uses_governed_column_policy(): void
    {
        $this->seed(FieldDefinitionSeeder::class);
        [$workspace, $type, $product, $group] = $this->fixture();
        $definition = FieldDefinition::withoutWorkspaceScope()
            ->whereNull('workspace_id')
            ->where('code', 'name')
            ->sole();
        $binding = FieldBinding::withoutWorkspaceScope()
            ->whereNull('workspace_id')
            ->where('field_definition_id', $definition->id)
            ->where('object_type', FieldObjectType::Product->value)
            ->where('storage_type', AttributeStorageType::Column->value)
            ->where('storage_path', 'products.name')
            ->sole();
        $this->place($workspace, $type, $group, $binding, required: true);

        $valid = app(ProductCompletenessService::class)->project($product, 'uk');
        $this->assertSame(1, $valid->filledCount);

        Product::withoutWorkspaceScope()->whereKey($product->id)->update(['name' => '   ']);
        $invalid = app(ProductCompletenessService::class)->project($product, 'uk');
        $this->assertSame(0, $invalid->filledCount);
        $this->assertSame([$binding->id], $invalid->missingProductBindingIds);
    }

    #[Test]
    public function unsupported_column_binding_fails_safe_without_reading_storage_path_directly(): void
    {
        [$workspace, $type, $product, $group] = $this->fixture();
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'unsafe_column',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['en' => 'Unsafe Column'],
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);
        $binding = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Column,
            'storage_path' => 'products.brand',
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 100,
            'status' => AttributeStatus::Active,
        ]);
        $this->place($workspace, $type, $group, $binding, required: true);
        Product::withoutWorkspaceScope()->whereKey($product->id)->update(['brand' => 'Present but unsupported']);

        $projection = app(ProductCompletenessService::class)->project($product, 'uk');

        $this->assertSame(1, $projection->requiredCount);
        $this->assertSame(0, $projection->filledCount);
        $this->assertSame([$binding->id], $projection->missingProductBindingIds);
    }

    #[Test]
    public function projection_query_count_is_bounded_across_many_groups_and_variants(): void
    {
        [$workspace, $type, $product] = $this->fixture(includeGroup: false);
        $variants = collect(range(1, 5))->map(fn () => $this->variant($workspace, $product, true));

        foreach (range(1, 8) as $index) {
            $group = $this->groupPlacement($workspace, $type, 'batch_'.$index, true, true, $index * 100);
            $binding = $this->dynamicBinding($workspace, 'batch_field_'.$index, FieldObjectType::ProductVariant);
            $this->place($workspace, $type, $group, $binding, required: true);

            foreach ($variants as $variant) {
                VariantFieldValue::withoutWorkspaceScope()->create([
                    'workspace_id' => $workspace->id,
                    'variant_id' => $variant->id,
                    'field_binding_id' => $binding->id,
                    'value_text' => 'value',
                ]);
            }
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $projection = app(ProductCompletenessService::class)->project($product, 'uk');

        $this->assertSame(8, $projection->requiredCount);
        $this->assertSame(8, $projection->filledCount);
        $this->assertLessThanOrEqual(12, count($queries), implode("\n", $queries));
    }

    /** @return array{0:Workspace,1:ProductType,2:Product,3?:ProductTypeGroupPlacement} */
    private function fixture(bool $includeGroup = true): array
    {
        $workspace = Workspace::query()->create(['name' => 'Completeness Workspace']);
        $type = ProductType::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'completeness_type_'.Str::lower(Str::random(6)),
            'localized_labels' => ['en' => 'Completeness Type'],
            'status' => 'active',
            'is_default' => false,
            'structure_revision' => 1,
        ]);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'COMP-'.Str::upper(Str::random(8)),
            'name' => 'Completeness Product',
            'is_active' => true,
        ]);
        $product->forceFill(['product_type_id' => $type->id])->save();

        if (! $includeGroup) {
            return [$workspace, $type, $product];
        }

        return [$workspace, $type, $product, $this->groupPlacement($workspace, $type, 'general')];
    }

    private function groupPlacement(
        Workspace $workspace,
        ProductType $type,
        string $code,
        bool $optional = false,
        bool $defaultActive = true,
        int $sortOrder = 100,
    ): ProductTypeGroupPlacement {
        $group = AttributeGroup::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => $code,
            'localized_labels' => ['en' => Str::headline($code)],
            'status' => 'active',
        ]);

        return ProductTypeGroupPlacement::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_type_id' => $type->id,
            'attribute_group_id' => $group->id,
            'sort_order' => $sortOrder,
            'is_optional' => $optional,
            'default_active' => $defaultActive,
        ]);
    }

    private function dynamicBinding(
        Workspace $workspace,
        string $code,
        FieldObjectType $objectType,
        AttributeDataType $dataType = AttributeDataType::Text,
        bool $localizable = false,
        bool $multiValue = false,
        ?array $validationRules = null,
    ): FieldBinding {
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => $code,
            'data_type' => $dataType,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['en' => Str::headline($code)],
            'validation_rules' => $validationRules,
            'is_localizable' => $localizable,
            'is_multi_value' => $multiValue,
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

    private function place(
        Workspace $workspace,
        ProductType $type,
        ProductTypeGroupPlacement $group,
        FieldBinding $binding,
        bool $required,
    ): ProductTypeFieldPlacement {
        return ProductTypeFieldPlacement::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_type_id' => $type->id,
            'product_type_group_placement_id' => $group->id,
            'field_binding_id' => $binding->id,
            'sort_order' => 100,
            'required_for_completeness' => $required,
        ]);
    }

    private function variant(Workspace $workspace, Product $product, bool $active): ProductVariant
    {
        return ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'COMP-V-'.Str::upper(Str::random(8)),
            'is_active' => $active,
        ]);
    }
}
