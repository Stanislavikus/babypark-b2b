<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Exceptions\Catalog\ColumnFieldNotAllowlistedException;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductFieldValue;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use App\Models\Workspace;
use App\Services\Catalog\GovernedProductVariantColumnMutationService;
use App\Services\Fields\GovernedDynamicFieldValueWriter;
use Database\Seeders\FieldDefinitionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanonicalActiveFieldFoundationCompletionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{AttributeDataType, string, string, array<string, string>}> */
    private array $columnFields = [
        'barcode_box' => [AttributeDataType::Text, 'products.barcode_box', 'identifiers', ['en' => 'Box Barcode', 'uk' => 'Штрихкод коробки', 'ru' => 'Штрихкод коробки']],
        'min_order_quantity' => [AttributeDataType::Number, 'products.min_order_quantity', 'b2b', ['en' => 'Minimum Order Quantity', 'uk' => 'Мін. кількість замовлення', 'ru' => 'Мин. количество заказа']],
        'order_step' => [AttributeDataType::Number, 'products.order_step', 'b2b', ['en' => 'Order Step', 'uk' => 'Крок замовлення', 'ru' => 'Шаг заказа']],
        'package_quantity' => [AttributeDataType::Number, 'products.package_quantity', 'logistics', ['en' => 'Package Quantity', 'uk' => 'Кількість в упаковці', 'ru' => 'Количество в упаковке']],
        'package_type' => [AttributeDataType::Text, 'products.package_type', 'logistics', ['en' => 'Package Type', 'uk' => 'Тип упаковки', 'ru' => 'Тип упаковки']],
        'units_per_box' => [AttributeDataType::Number, 'products.units_per_box', 'logistics', ['en' => 'Units Per Box', 'uk' => 'Одиниць у коробці', 'ru' => 'Единиц в коробке']],
        'boxes_per_pallet' => [AttributeDataType::Number, 'products.boxes_per_pallet', 'logistics', ['en' => 'Boxes Per Pallet', 'uk' => 'Коробок на палеті', 'ru' => 'Коробок на паллете']],
        'lead_time_days' => [AttributeDataType::Number, 'products.lead_time_days', 'logistics', ['en' => 'Lead Time Days', 'uk' => 'Термін поставки (дні)', 'ru' => 'Срок поставки (дни)']],
        'depth_mm' => [AttributeDataType::Number, 'products.depth_mm', 'logistics', ['en' => 'Depth', 'uk' => 'Глибина', 'ru' => 'Глубина']],
        'width_mm' => [AttributeDataType::Number, 'products.width_mm', 'logistics', ['en' => 'Width', 'uk' => 'Ширина', 'ru' => 'Ширина']],
        'height_mm' => [AttributeDataType::Number, 'products.height_mm', 'logistics', ['en' => 'Height', 'uk' => 'Висота', 'ru' => 'Высота']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(FieldDefinitionSeeder::class);
    }

    public function test_safe_materialization_set_has_exact_canonical_metadata_and_bindings(): void
    {
        foreach ($this->columnFields as $code => [$dataType, $storagePath, $group, $labels]) {
            $definition = $this->definition($code);
            $this->assertDefinition($definition, $dataType, AttributeScope::System, $labels, false);
            $this->assertExactBindings($definition, [[FieldObjectType::Product, AttributeStorageType::Column, $storagePath, $group]]);
        }

        foreach (['pattern' => ['Pattern', 'Візерунок', 'Узор'], 'style' => ['Style', 'Стиль', 'Стиль']] as $code => $labels) {
            $definition = $this->definition($code);
            $this->assertDefinition($definition, AttributeDataType::Text, AttributeScope::PlatformLibrary, array_combine(['en', 'uk', 'ru'], $labels), false);
            $this->assertExactBindings($definition, [
                [FieldObjectType::Product, AttributeStorageType::Dynamic, null, 'characteristics'],
                [FieldObjectType::ProductVariant, AttributeStorageType::Dynamic, null, 'characteristics'],
            ]);
        }

        $warranty = $this->definition('warranty');
        $this->assertDefinition($warranty, AttributeDataType::LongText, AttributeScope::PlatformLibrary, ['en' => 'Warranty Description', 'uk' => 'Гарантія', 'ru' => 'Гарантия'], true);
        $this->assertExactBindings($warranty, [[FieldObjectType::Product, AttributeStorageType::Dynamic, null, 'descriptions']]);
    }

    public function test_seeder_is_idempotent_and_exclusions_remain_unseeded(): void
    {
        $counts = [FieldDefinition::withoutWorkspaceScope()->count(), FieldBinding::withoutWorkspaceScope()->count()];
        $this->seed(FieldDefinitionSeeder::class);
        $this->assertSame($counts, [FieldDefinition::withoutWorkspaceScope()->count(), FieldBinding::withoutWorkspaceScope()->count()]);

        $this->assertSame(0, FieldDefinition::withoutWorkspaceScope()->whereIn('code', [
            'unit', 'meta_title', 'meta_description', 'product_highlights', 'age_group', 'gender',
        ])->count());
    }

    public function test_new_dynamic_bindings_use_existing_product_and_variant_write_paths(): void
    {
        [$workspace, $product, $variant] = $this->targets();
        $writer = app(GovernedDynamicFieldValueWriter::class);
        $productBinding = $this->binding('pattern', FieldObjectType::Product);
        $variantBinding = $this->binding('pattern', FieldObjectType::ProductVariant);

        $writer->set($workspace->id, FieldObjectType::Product, $product->id, $productBinding->id, 'Смугастий');
        $writer->set($workspace->id, FieldObjectType::ProductVariant, $variant->id, $variantBinding->id, 'Крапка');

        $this->assertSame('Смугастий', ProductFieldValue::withoutWorkspaceScope()->where('field_binding_id', $productBinding->id)->sole()->value_text);
        $this->assertSame('Крапка', VariantFieldValue::withoutWorkspaceScope()->where('field_binding_id', $variantBinding->id)->sole()->value_text);
    }

    public function test_warranty_uses_localized_set_clear_and_new_column_binding_stays_fail_closed(): void
    {
        [$workspace, $product] = $this->targets();
        $writer = app(GovernedDynamicFieldValueWriter::class);
        $warranty = $this->binding('warranty', FieldObjectType::Product);
        $writer->set($workspace->id, FieldObjectType::Product, $product->id, $warranty->id, 'Два роки', 'uk');
        $writer->set($workspace->id, FieldObjectType::Product, $product->id, $warranty->id, 'Two years', 'en');
        $writer->clear($workspace->id, FieldObjectType::Product, $product->id, $warranty->id, 'en');

        $this->assertSame(['uk' => 'Два роки'], ProductFieldValue::withoutWorkspaceScope()->where('field_binding_id', $warranty->id)->sole()->value_jsonb);

        $barcode = $this->binding('barcode_box', FieldObjectType::Product);
        $this->expectException(ColumnFieldNotAllowlistedException::class);
        app(GovernedProductVariantColumnMutationService::class)->set($workspace->id, FieldObjectType::Product, $product->id, $barcode->id, '4820000000000');
    }

    private function definition(string $code): FieldDefinition
    {
        return FieldDefinition::withoutWorkspaceScope()->where('code', $code)->sole();
    }

    private function binding(string $code, FieldObjectType $type): FieldBinding
    {
        return FieldBinding::withoutWorkspaceScope()->whereBelongsTo($this->definition($code))->where('object_type', $type)->sole();
    }

    private function assertDefinition(FieldDefinition $definition, AttributeDataType $type, AttributeScope $scope, array $labels, bool $localizable): void
    {
        $this->assertSame($type, $definition->data_type);
        $this->assertSame($scope, $definition->scope);
        $this->assertSame(AttributeStatus::Active, $definition->status);
        $this->assertSame($labels, $definition->localized_labels);
        $this->assertSame($localizable, $definition->is_localizable);
        $this->assertFalse($definition->is_multi_value);
    }

    private function assertExactBindings(FieldDefinition $definition, array $expected): void
    {
        $actual = FieldBinding::withoutWorkspaceScope()->whereBelongsTo($definition)->orderBy('object_type')->get();
        $this->assertCount(count($expected), $actual);
        foreach ($expected as [$objectType, $storageType, $storagePath, $group]) {
            $binding = $actual->firstWhere('object_type', $objectType);
            $this->assertNotNull($binding);
            $this->assertSame($storageType, $binding->storage_type);
            $this->assertSame($storagePath, $binding->storage_path);
            $this->assertSame($group, $binding->field_group);
            $this->assertSame(AttributeStatus::Active, $binding->status);
        }
    }

    /** @return array{Workspace, Product, ProductVariant} */
    private function targets(): array
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->create(['workspace_id' => $workspace->id, 'onec_guid' => (string) Str::uuid(), 'sku' => Str::uuid(), 'name' => 'Test', 'unit' => 'шт', 'is_active' => true]);
        $variant = ProductVariant::query()->create(['workspace_id' => $workspace->id, 'product_id' => $product->id, 'onec_guid' => (string) Str::uuid(), 'sku' => Str::uuid(), 'is_active' => true]);

        return [$workspace, $product, $variant];
    }
}
