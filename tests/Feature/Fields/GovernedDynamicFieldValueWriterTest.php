<?php

namespace Tests\Feature\Fields;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductFieldValue;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use App\Models\Workspace;
use App\Services\Fields\Exceptions\FieldBindingArchivedException;
use App\Services\Fields\Exceptions\FieldBindingNotFoundException;
use App\Services\Fields\Exceptions\FieldBindingObjectTypeMismatchException;
use App\Services\Fields\Exceptions\FieldBindingStorageTypeMismatchException;
use App\Services\Fields\Exceptions\FieldBindingWorkspaceMismatchException;
use App\Services\Fields\Exceptions\FieldDefinitionArchivedException;
use App\Services\Fields\Exceptions\FieldDefinitionWorkspaceMismatchException;
use App\Services\Fields\Exceptions\FieldValueWriterException;
use App\Services\Fields\Exceptions\InvalidFieldValuePayloadException;
use App\Services\Fields\Exceptions\InvalidSelectOptionException;
use App\Services\Fields\Exceptions\LocalizationContractViolationException;
use App\Services\Fields\Exceptions\MultiValueNotSupportedException;
use App\Services\Fields\Exceptions\TargetNotFoundException;
use App\Services\Fields\Exceptions\TargetWorkspaceMismatchException;
use App\Services\Fields\Exceptions\UnsupportedFieldDataTypeException;
use App\Services\Fields\Exceptions\UnsupportedFieldObjectTypeException;
use App\Services\Fields\Exceptions\UnsupportedFieldValidationRulesException;
use App\Services\Fields\FieldValueWriteResult;
use App\Services\Fields\GovernedDynamicFieldValueWriter;
use Database\Seeders\FieldDefinitionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GovernedDynamicFieldValueWriterTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Product $product;

    private ProductVariant $variant;

    private GovernedDynamicFieldValueWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(FieldDefinitionSeeder::class);

        $this->workspace = Workspace::query()->where('is_default', true)->firstOrFail();
        $this->writer = app(GovernedDynamicFieldValueWriter::class);

        $this->product = Product::query()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'TEST-PRODUCT-001',
            'name' => 'Test Product',
            'unit' => 'шт',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::query()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $this->product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'TEST-VARIANT-001',
            'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------
    // Happy path: set + clear on supported datatypes
    // ---------------------------------------------------------------

    public function test_set_creates_variant_dynamic_text_value_row(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);

        $result = $this->writer->set(
            workspaceId: $this->workspace->id,
            targetType: FieldObjectType::ProductVariant,
            targetId: $variant->id,
            fieldBindingId: $binding->id,
            value: 'ABC-123',
        );

        $this->assertSame(FieldValueWriteResult::Created, $result->status);
        $this->assertSame($binding->id, $result->fieldBindingId);

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('variant_id', $variant->id)
            ->where('field_binding_id', $binding->id)
            ->firstOrFail();

        $this->assertSame('ABC-123', $row->value_text);
        $this->assertNull($row->value_num);
        $this->assertNull($row->value_jsonb);
    }

    public function test_set_updates_existing_value_row(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);

        $this->writer->set($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id, 'OLD');
        $result = $this->writer->set($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id, 'NEW');

        $this->assertSame(FieldValueWriteResult::Updated, $result->status);

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('variant_id', $variant->id)
            ->where('field_binding_id', $binding->id)
            ->firstOrFail();

        $this->assertSame('NEW', $row->value_text);
    }

    public function test_set_same_value_is_noop_with_no_db_mutation(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);

        $this->writer->set($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id, 'SAME');
        $rowBefore = VariantFieldValue::withoutWorkspaceScope()
            ->where('variant_id', $variant->id)
            ->where('field_binding_id', $binding->id)
            ->firstOrFail();
        $updatedAtBefore = $rowBefore->updated_at;

        $result = $this->writer->set($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id, 'SAME');

        $this->assertSame(FieldValueWriteResult::NoOp, $result->status);
        $this->assertFalse($result->isMutation());

        $rowAfter = VariantFieldValue::withoutWorkspaceScope()
            ->where('variant_id', $variant->id)
            ->where('field_binding_id', $binding->id)
            ->firstOrFail();

        $this->assertSame($updatedAtBefore->toAtomString(), $rowAfter->updated_at->toAtomString());
    }

    public function test_set_empty_string_for_text_is_a_legitimate_explicit_value(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);

        $result = $this->writer->set($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id, '');

        $this->assertSame(FieldValueWriteResult::Created, $result->status);

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('variant_id', $variant->id)
            ->where('field_binding_id', $binding->id)
            ->firstOrFail();

        $this->assertSame('', $row->value_text);
    }

    public function test_set_same_value_with_stale_other_payload_columns_canonicalizes_row(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'variant_id' => $variant->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'ABC',
            'value_num' => 12.5,
            'value_jsonb' => ['legacy' => 'value'],
        ]);

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $variant->id,
            $binding->id,
            'ABC',
        );

        $this->assertSame(FieldValueWriteResult::Updated, $result->status);

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertSame('ABC', $row->value_text);
        $this->assertNull($row->value_num);
        $this->assertNull($row->value_jsonb);
    }

    public function test_set_ignores_foreign_workspace_variant_row_for_same_slot(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign Value Workspace', 'is_default' => false]);

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'variant_id' => $variant->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'FOREIGN',
        ]);

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $variant->id,
            $binding->id,
            'LOCAL',
        );

        $this->assertSame(FieldValueWriteResult::Created, $result->status);

        $foreignRow = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $foreignWorkspace->id)
            ->where('variant_id', $variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();
        $localRow = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertSame('FOREIGN', $foreignRow->value_text);
        $this->assertSame('LOCAL', $localRow->value_text);
    }

    public function test_set_null_payload_is_rejected(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);

        $this->expectException(InvalidFieldValuePayloadException::class);

        $this->writer->set($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id, null);
    }

    public function test_clear_non_localizable_value_deletes_row(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);

        $this->writer->set($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id, 'ABC');
        $this->assertDatabaseHas('variant_field_values', ['variant_id' => $variant->id, 'field_binding_id' => $binding->id]);

        $result = $this->writer->clear($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id);

        $this->assertSame(FieldValueWriteResult::Deleted, $result->status);
        $this->assertDatabaseMissing('variant_field_values', ['variant_id' => $variant->id, 'field_binding_id' => $binding->id]);
    }

    public function test_clear_on_absent_value_is_noop(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);

        $result = $this->writer->clear($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id);

        $this->assertSame(FieldValueWriteResult::NoOp, $result->status);
    }

    public function test_clear_ignores_foreign_workspace_product_row_for_same_slot(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('material', FieldObjectType::Product);
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign Product Value Workspace', 'is_default' => false]);

        ProductFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'FOREIGN-BRAND',
        ]);

        $result = $this->writer->clear(
            $this->workspace->id,
            FieldObjectType::Product,
            $product->id,
            $binding->id,
        );

        $this->assertSame(FieldValueWriteResult::NoOp, $result->status);

        $foreignRow = ProductFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $foreignWorkspace->id)
            ->where('product_id', $product->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertSame('FOREIGN-BRAND', $foreignRow->value_text);
        $this->assertDatabaseMissing('product_field_values', [
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $binding->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Select option validation
    // ---------------------------------------------------------------

    public function test_set_select_persists_stable_option_code(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('color', FieldObjectType::ProductVariant);

        $result = $this->writer->set($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id, 'blue');

        $this->assertSame(FieldValueWriteResult::Created, $result->status);

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('variant_id', $variant->id)
            ->where('field_binding_id', $binding->id)
            ->firstOrFail();

        $this->assertSame('blue', $row->value_text);
    }

    public function test_set_select_rejects_undeclared_option(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('color', FieldObjectType::ProductVariant);

        $this->expectException(InvalidSelectOptionException::class);

        $this->writer->set($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id, 'red');
    }

    public function test_set_select_rejects_display_label_instead_of_code(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('color', FieldObjectType::ProductVariant);

        $this->expectException(InvalidSelectOptionException::class);

        $this->writer->set($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id, 'Синій');
    }

    public function test_set_select_rejects_non_string_payload(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('color', FieldObjectType::ProductVariant);

        $this->expectException(InvalidFieldValuePayloadException::class);

        $this->writer->set($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id, ['blue']);
    }

    public function test_set_rejects_localizable_select_definition(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'localizable_select_not_allowed',
            dataType: AttributeDataType::Select,
            objectType: FieldObjectType::ProductVariant,
            isLocalizable: true,
            validationRules: [
                'options' => [
                    ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                ],
            ],
        );

        $this->expectException(LocalizationContractViolationException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            'blue',
            'uk',
        );
    }

    public function test_set_rejects_unknown_text_validation_rules(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'text_with_unknown_rule',
            dataType: AttributeDataType::Text,
            objectType: FieldObjectType::ProductVariant,
            validationRules: ['max_length' => 255],
        );

        try {
            $this->writer->set(
                $this->workspace->id,
                FieldObjectType::ProductVariant,
                $this->variant->id,
                $binding->id,
                'ABC',
            );
            $this->fail('Expected UnsupportedFieldValidationRulesException was not thrown.');
        } catch (UnsupportedFieldValidationRulesException) {
            $this->assertDatabaseMissing('variant_field_values', [
                'workspace_id' => $this->workspace->id,
                'variant_id' => $this->variant->id,
                'field_binding_id' => $binding->id,
            ]);
        }
    }

    public function test_set_rejects_unknown_select_validation_rules(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'select_with_unknown_rule',
            dataType: AttributeDataType::Select,
            objectType: FieldObjectType::ProductVariant,
            validationRules: [
                'options' => [
                    ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                ],
                'max_items' => 1,
            ],
        );

        try {
            $this->writer->set(
                $this->workspace->id,
                FieldObjectType::ProductVariant,
                $this->variant->id,
                $binding->id,
                'blue',
            );
            $this->fail('Expected UnsupportedFieldValidationRulesException was not thrown.');
        } catch (UnsupportedFieldValidationRulesException) {
            $this->assertDatabaseMissing('variant_field_values', [
                'workspace_id' => $this->workspace->id,
                'variant_id' => $this->variant->id,
                'field_binding_id' => $binding->id,
            ]);
        }
    }

    // ---------------------------------------------------------------
    // Localization (single-locale API, locked read-modify-write)
    // ---------------------------------------------------------------

    public function test_set_localizable_text_writes_only_value_jsonb(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('short_description', FieldObjectType::Product);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $product->id,
            $binding->id,
            'Короткий опис',
            'uk',
        );

        $row = ProductFieldValue::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->where('field_binding_id', $binding->id)
            ->firstOrFail();

        $this->assertNull($row->value_text);
        $this->assertNull($row->value_num);
        $this->assertSame(['uk' => 'Короткий опис'], $row->value_jsonb);
    }

    public function test_concurrent_set_uk_then_set_en_preserves_both_locales(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('short_description', FieldObjectType::Product);

        $r1 = $this->writer->set($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'Укр', 'uk');
        $r2 = $this->writer->set($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'En', 'en');

        $this->assertSame(FieldValueWriteResult::Created, $r1->status);
        $this->assertSame(FieldValueWriteResult::Updated, $r2->status);

        $row = ProductFieldValue::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->where('field_binding_id', $binding->id)
            ->firstOrFail();

        $this->assertIsArray($row->value_jsonb);
        $this->assertCount(2, $row->value_jsonb);
        $this->assertSame('Укр', $row->value_jsonb['uk'] ?? null);
        $this->assertSame('En', $row->value_jsonb['en'] ?? null);
        $locales = array_keys($row->value_jsonb);
        sort($locales);
        $this->assertSame(['en', 'uk'], $locales);
    }

    public function test_clear_localizable_removes_only_targeted_locale(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('short_description', FieldObjectType::Product);

        $this->writer->set($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'Укр', 'uk');
        $this->writer->set($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'En', 'en');

        $r = $this->writer->clear($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'en');

        $this->assertSame(FieldValueWriteResult::Updated, $r->status);

        $row = ProductFieldValue::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->where('field_binding_id', $binding->id)
            ->firstOrFail();

        $this->assertSame(['uk' => 'Укр'], $row->value_jsonb);
    }

    public function test_clear_last_locale_deletes_the_row(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('short_description', FieldObjectType::Product);

        $this->writer->set($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'Укр', 'uk');

        $r = $this->writer->clear($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'uk');

        $this->assertSame(FieldValueWriteResult::Deleted, $r->status);
        $this->assertDatabaseMissing('product_field_values', ['product_id' => $product->id, 'field_binding_id' => $binding->id]);
    }

    public function test_clear_absent_locale_is_noop(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('short_description', FieldObjectType::Product);

        $this->writer->set($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'Укр', 'uk');

        $r = $this->writer->clear($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'en');

        $this->assertSame(FieldValueWriteResult::NoOp, $r->status);

        $row = ProductFieldValue::withoutWorkspaceScope()
            ->where('product_id', $product->id)
            ->where('field_binding_id', $binding->id)
            ->firstOrFail();

        $this->assertSame(['uk' => 'Укр'], $row->value_jsonb);
    }

    public function test_localizable_set_requires_locale(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('short_description', FieldObjectType::Product);

        $this->expectException(LocalizationContractViolationException::class);

        $this->writer->set($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'x');
    }

    public function test_non_localizable_set_rejects_locale(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);

        $this->expectException(LocalizationContractViolationException::class);

        $this->writer->set($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id, 'x', 'uk');
    }

    public function test_set_accepts_non_empty_opaque_locale_key(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('short_description', FieldObjectType::Product);

        $this->writer->set($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'x', '!!!');

        $row = ProductFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('product_id', $product->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertSame(['!!!' => 'x'], $row->value_jsonb);
    }

    public function test_corrupt_existing_localized_storage_is_rejected(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('short_description', FieldObjectType::Product);

        ProductFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $binding->id,
            'value_jsonb' => ['not_a_string_key' => 123],
        ]);

        $this->expectException(LocalizationContractViolationException::class);

        $this->writer->set($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'Укр', 'uk');
    }

    public function test_set_localizable_flat_legacy_row_fails_closed_and_preserves_original(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('short_description', FieldObjectType::Product);

        ProductFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'legacy-flat',
            'value_jsonb' => null,
        ]);

        try {
            $this->writer->set($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'Нове', 'uk');
            $this->fail('Expected LocalizationContractViolationException was not thrown.');
        } catch (LocalizationContractViolationException) {
            $row = ProductFieldValue::withoutWorkspaceScope()
                ->where('workspace_id', $this->workspace->id)
                ->where('product_id', $product->id)
                ->where('field_binding_id', $binding->id)
                ->sole();

            $this->assertSame('legacy-flat', $row->value_text);
            $this->assertNull($row->value_num);
            $this->assertNull($row->value_jsonb);
        }
    }

    public function test_clear_localizable_flat_legacy_row_fails_closed_and_preserves_original(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('short_description', FieldObjectType::Product);

        ProductFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'legacy-flat',
            'value_jsonb' => null,
        ]);

        try {
            $this->writer->clear($this->workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'uk');
            $this->fail('Expected LocalizationContractViolationException was not thrown.');
        } catch (LocalizationContractViolationException) {
            $row = ProductFieldValue::withoutWorkspaceScope()
                ->where('workspace_id', $this->workspace->id)
                ->where('product_id', $product->id)
                ->where('field_binding_id', $binding->id)
                ->sole();

            $this->assertSame('legacy-flat', $row->value_text);
            $this->assertNull($row->value_num);
            $this->assertNull($row->value_jsonb);
        }
    }

    // ---------------------------------------------------------------
    // Canonical storage after Set
    // ---------------------------------------------------------------

    public function test_set_canonicalizes_text_value_by_nulling_other_columns(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'variant_id' => $variant->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'OLD',
            'value_num' => 12.5,
            'value_jsonb' => ['x' => 'y'],
        ]);

        $this->writer->set($this->workspace->id, FieldObjectType::ProductVariant, $variant->id, $binding->id, 'NEW');

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('variant_id', $variant->id)
            ->where('field_binding_id', $binding->id)
            ->firstOrFail();

        $this->assertSame('NEW', $row->value_text);
        $this->assertNull($row->value_num);
        $this->assertNull($row->value_jsonb);
    }

    // ---------------------------------------------------------------
    // Number / Decimal
    // ---------------------------------------------------------------

    #[DataProvider('numericAcceptedPayloadProvider')]
    public function test_set_numeric_types_accept_supported_payloads(
        AttributeDataType $dataType,
        int|string $payload,
        string $expectedValueNum,
    ): void {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'numeric_accept_'.$dataType->value.'_'.Str::lower(Str::random(6)),
            dataType: $dataType,
            objectType: FieldObjectType::ProductVariant,
        );

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            $payload,
        );

        $this->assertSame(FieldValueWriteResult::Created, $result->status);

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertNull($row->value_text);
        $this->assertSame($expectedValueNum, $row->value_num);
        $this->assertNull($row->value_jsonb);
    }

    #[DataProvider('numericCanonicalNoOpProvider')]
    public function test_set_numeric_types_treat_canonical_same_value_as_noop(
        AttributeDataType $dataType,
        string $initialPayload,
        int|string $secondPayload,
        string $expectedValueNum,
    ): void {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'numeric_noop_'.$dataType->value.'_'.Str::lower(Str::random(6)),
            dataType: $dataType,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            $initialPayload,
        );

        $rowBefore = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();
        $updatedAtBefore = $rowBefore->updated_at;

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            $secondPayload,
        );

        $this->assertSame(FieldValueWriteResult::NoOp, $result->status);

        $rowAfter = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertSame($expectedValueNum, $rowAfter->value_num);
        $this->assertSame($updatedAtBefore->toAtomString(), $rowAfter->updated_at->toAtomString());
        $this->assertNull($rowAfter->value_text);
        $this->assertNull($rowAfter->value_jsonb);
    }

    #[DataProvider('numericTypeProvider')]
    public function test_set_numeric_types_canonicalize_stale_other_payload_columns(AttributeDataType $dataType): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'numeric_stale_'.$dataType->value.'_'.Str::lower(Str::random(6)),
            dataType: $dataType,
            objectType: FieldObjectType::ProductVariant,
        );

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'variant_id' => $this->variant->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'stale',
            'value_num' => '12.500000',
            'value_jsonb' => ['legacy'],
        ]);

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            '12.5',
        );

        $this->assertSame(FieldValueWriteResult::Updated, $result->status);

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertNull($row->value_text);
        $this->assertSame('12.500000', $row->value_num);
        $this->assertNull($row->value_jsonb);
    }

    #[DataProvider('numericTypeProvider')]
    public function test_set_numeric_types_reject_float_payloads(AttributeDataType $dataType): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'numeric_float_'.$dataType->value.'_'.Str::lower(Str::random(6)),
            dataType: $dataType,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->expectException(InvalidFieldValuePayloadException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            12.5,
        );
    }

    #[DataProvider('numericTypeProvider')]
    public function test_set_numeric_types_reject_exponent_notation(AttributeDataType $dataType): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'numeric_exp_'.$dataType->value.'_'.Str::lower(Str::random(6)),
            dataType: $dataType,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->expectException(InvalidFieldValuePayloadException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            '1e3',
        );
    }

    #[DataProvider('invalidNumericPayloadProvider')]
    public function test_set_numeric_types_reject_invalid_numeric_strings(string $payload): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'numeric_invalid_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Number,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->expectException(InvalidFieldValuePayloadException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            $payload,
        );
    }

    #[DataProvider('numericTypeProvider')]
    public function test_set_numeric_types_reject_excess_meaningful_scale_without_rounding(AttributeDataType $dataType): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'numeric_scale_'.$dataType->value.'_'.Str::lower(Str::random(6)),
            dataType: $dataType,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->expectException(InvalidFieldValuePayloadException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            '1.1234567',
        );
    }

    #[DataProvider('numericTypeProvider')]
    public function test_set_numeric_types_reject_localizable_definitions(AttributeDataType $dataType): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'numeric_localizable_'.$dataType->value.'_'.Str::lower(Str::random(6)),
            dataType: $dataType,
            objectType: FieldObjectType::ProductVariant,
            isLocalizable: true,
        );

        $this->expectException(LocalizationContractViolationException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            '12',
            'uk',
        );
    }

    // ---------------------------------------------------------------
    // Boolean
    // ---------------------------------------------------------------

    public function test_set_boolean_true_uses_value_num_only(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'boolean_true_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Boolean,
            objectType: FieldObjectType::ProductVariant,
        );

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            true,
        );

        $this->assertSame(FieldValueWriteResult::Created, $result->status);

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertNull($row->value_text);
        $this->assertSame('1.000000', $row->value_num);
        $this->assertNull($row->value_jsonb);
    }

    public function test_set_boolean_false_uses_value_num_only(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'boolean_false_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Boolean,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            false,
        );

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertNull($row->value_text);
        $this->assertSame('0.000000', $row->value_num);
        $this->assertNull($row->value_jsonb);
    }

    #[DataProvider('invalidBooleanPayloadProvider')]
    public function test_set_boolean_rejects_pseudo_boolean_payloads(mixed $payload): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'boolean_invalid_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Boolean,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->expectException(InvalidFieldValuePayloadException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            $payload,
        );
    }

    public function test_set_boolean_same_value_is_noop(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'boolean_noop_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Boolean,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            true,
        );

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            true,
        );

        $this->assertSame(FieldValueWriteResult::NoOp, $result->status);
    }

    public function test_set_boolean_canonicalizes_stale_payload_columns(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'boolean_stale_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Boolean,
            objectType: FieldObjectType::ProductVariant,
        );

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'variant_id' => $this->variant->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'true',
            'value_num' => '1.000000',
            'value_jsonb' => ['legacy' => true],
        ]);

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            true,
        );

        $this->assertSame(FieldValueWriteResult::Updated, $result->status);

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertNull($row->value_text);
        $this->assertSame('1.000000', $row->value_num);
        $this->assertNull($row->value_jsonb);
    }

    // ---------------------------------------------------------------
    // Date
    // ---------------------------------------------------------------

    public function test_set_date_stores_valid_date_in_value_text_only(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'date_valid_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Date,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            '2026-08-26',
        );

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertSame('2026-08-26', $row->value_text);
        $this->assertNull($row->value_num);
        $this->assertNull($row->value_jsonb);
    }

    public function test_set_date_allows_real_leap_day_and_rejects_invalid_leap_day(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'date_leap_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Date,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            '2024-02-29',
        );

        $this->expectException(InvalidFieldValuePayloadException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            '2026-02-29',
        );
    }

    #[DataProvider('invalidDatePayloadProvider')]
    public function test_set_date_rejects_invalid_formats(string $payload): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'date_invalid_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Date,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->expectException(InvalidFieldValuePayloadException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            $payload,
        );
    }

    // ---------------------------------------------------------------
    // Url
    // ---------------------------------------------------------------

    #[DataProvider('validUrlProvider')]
    public function test_set_url_accepts_valid_absolute_http_and_https_urls(string $payload): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'url_valid_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Url,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            $payload,
        );

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertSame($payload, $row->value_text);
        $this->assertNull($row->value_num);
        $this->assertNull($row->value_jsonb);
    }

    #[DataProvider('invalidUrlPayloadProvider')]
    public function test_set_url_rejects_invalid_urls(mixed $payload): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'url_invalid_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Url,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->expectException(InvalidFieldValuePayloadException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            $payload,
        );
    }

    // ---------------------------------------------------------------
    // MultiSelect
    // ---------------------------------------------------------------

    public function test_set_multiselect_stores_sorted_internal_option_codes_in_value_jsonb_only(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_select_valid_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: [
                'options' => [
                    ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                    ['code' => 'pink', 'labels' => ['uk' => 'Рожевий']],
                ],
            ],
        );

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            ['pink', 'blue'],
        );

        $this->assertSame(FieldValueWriteResult::Created, $result->status);

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertNull($row->value_text);
        $this->assertNull($row->value_num);
        $this->assertSame(['blue', 'pink'], $row->value_jsonb);
    }

    public function test_set_multiselect_different_input_order_is_noop_when_stored_value_is_already_canonical(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_select_noop_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: [
                'options' => [
                    ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                    ['code' => 'pink', 'labels' => ['uk' => 'Рожевий']],
                ],
            ],
        );

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            ['pink', 'blue'],
        );

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            ['blue', 'pink'],
        );

        $this->assertSame(FieldValueWriteResult::NoOp, $result->status);
    }

    public function test_set_multiselect_canonicalizes_stale_scalar_payload_columns(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_select_stale_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: [
                'options' => [
                    ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                    ['code' => 'pink', 'labels' => ['uk' => 'Рожевий']],
                ],
            ],
        );

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'variant_id' => $this->variant->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'legacy',
            'value_num' => '2.000000',
            'value_jsonb' => ['pink', 'blue'],
        ]);

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            ['blue', 'pink'],
        );

        $this->assertSame(FieldValueWriteResult::Updated, $result->status);

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertNull($row->value_text);
        $this->assertNull($row->value_num);
        $this->assertSame(['blue', 'pink'], $row->value_jsonb);
    }

    public function test_clear_multiselect_deletes_row(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_select_clear_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: [
                'options' => [
                    ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                    ['code' => 'pink', 'labels' => ['uk' => 'Рожевий']],
                ],
            ],
        );

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            ['blue', 'pink'],
        );

        $result = $this->writer->clear(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
        );

        $this->assertSame(FieldValueWriteResult::Deleted, $result->status);
        $this->assertDatabaseMissing('variant_field_values', [
            'workspace_id' => $this->workspace->id,
            'variant_id' => $this->variant->id,
            'field_binding_id' => $binding->id,
        ]);
    }

    public function test_set_multiselect_rejects_display_labels(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_select_labels_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: [
                'options' => [
                    ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                ],
            ],
        );

        $this->expectException(InvalidSelectOptionException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            ['Синій'],
        );
    }

    public function test_set_multiselect_rejects_unknown_option_codes(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_select_unknown_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: [
                'options' => [
                    ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                ],
            ],
        );

        $this->expectException(InvalidSelectOptionException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            ['blue', 'pink'],
        );
    }

    public function test_set_multiselect_rejects_duplicate_codes(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_select_dup_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: [
                'options' => [
                    ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                ],
            ],
        );

        $this->expectException(InvalidFieldValuePayloadException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            ['blue', 'blue'],
        );
    }

    public function test_set_multiselect_rejects_empty_list_associative_array_and_non_string_members(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_select_invalid_payload_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: [
                'options' => [
                    ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                    ['code' => 'pink', 'labels' => ['uk' => 'Рожевий']],
                ],
            ],
        );

        foreach ([[], ['first' => 'blue'], ['blue', 1]] as $payload) {
            try {
                $this->writer->set(
                    $this->workspace->id,
                    FieldObjectType::ProductVariant,
                    $this->variant->id,
                    $binding->id,
                    $payload,
                );
                $this->fail('Expected InvalidFieldValuePayloadException was not thrown.');
            } catch (InvalidFieldValuePayloadException) {
                $this->assertDatabaseMissing('variant_field_values', [
                    'workspace_id' => $this->workspace->id,
                    'variant_id' => $this->variant->id,
                    'field_binding_id' => $binding->id,
                ]);
            }
        }
    }

    public function test_set_multiselect_rejects_is_multi_value_false_metadata(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_select_wrong_cardinality_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: false,
            validationRules: [
                'options' => [
                    ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                ],
            ],
        );

        $this->expectException(MultiValueNotSupportedException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            ['blue'],
        );
    }

    public function test_set_multiselect_rejects_localizable_metadata(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_select_localizable_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isLocalizable: true,
            isMultiValue: true,
            validationRules: [
                'options' => [
                    ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                ],
            ],
        );

        $this->expectException(LocalizationContractViolationException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            ['blue'],
            'uk',
        );
    }

    // ---------------------------------------------------------------
    // Authorization / target / binding / definition
    // ---------------------------------------------------------------

    public function test_set_rejects_unknown_binding(): void
    {
        $variant = $this->variant;

        $this->expectException(FieldBindingNotFoundException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $variant->id,
            '00000000-0000-0000-0000-000000000000',
            'x',
        );
    }

    public function test_set_rejects_unknown_target(): void
    {
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);

        $this->expectException(TargetNotFoundException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            9_999_999_999,
            $binding->id,
            'x',
        );
    }

    public function test_set_rejects_object_type_mismatch(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);

        $this->expectException(FieldBindingObjectTypeMismatchException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $product->id,
            $binding->id,
            'x',
        );
    }

    public function test_set_rejects_column_backed_storage_type(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('name', FieldObjectType::Product, storage: AttributeStorageType::Column);

        $this->expectException(FieldBindingStorageTypeMismatchException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $product->id,
            $binding->id,
            'new name',
        );
    }

    public function test_set_rejects_relation_storage_type(): void
    {
        $product = $this->product;
        $binding = $this->dynamicBinding('category', FieldObjectType::Product, storage: AttributeStorageType::Relation);

        $this->expectException(FieldBindingStorageTypeMismatchException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $product->id,
            $binding->id,
            'ignored',
        );
    }

    public function test_set_rejects_archived_binding(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);
        $binding->update(['status' => AttributeStatus::Archived]);

        $this->expectException(FieldBindingArchivedException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $variant->id,
            $binding->id,
            'x',
        );
    }

    public function test_set_rejects_archived_definition(): void
    {
        $variant = $this->variant;
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);
        $definition = FieldDefinition::withoutWorkspaceScope()->findOrFail($binding->field_definition_id);
        $definition->update(['status' => AttributeStatus::Archived]);

        $this->expectException(FieldDefinitionArchivedException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $variant->id,
            $binding->id,
            'x',
        );
    }

    public function test_set_rejects_foreign_workspace_binding(): void
    {
        $variant = $this->variant;
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign', 'is_default' => false]);

        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => null,
            'code' => 'foreign_binding_test_def',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::PlatformLibrary,
            'localized_labels' => ['uk' => 'F'],
            'description' => null,
            'validation_rules' => [],
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        $foreignBinding = FieldBinding::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $foreignWorkspace->id,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::ProductVariant,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 0,
            'status' => AttributeStatus::Active,
        ]);

        $this->expectException(FieldBindingWorkspaceMismatchException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $variant->id,
            $foreignBinding->id,
            'x',
        );
    }

    public function test_set_rejects_target_workspace_mismatch(): void
    {
        $binding = $this->dynamicBinding('mpn', FieldObjectType::ProductVariant);
        $variant = $this->variant;
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign', 'is_default' => false]);

        $this->expectException(TargetWorkspaceMismatchException::class);

        $this->writer->set(
            $foreignWorkspace->id,
            FieldObjectType::ProductVariant,
            $variant->id,
            $binding->id,
            'x',
        );
    }

    public function test_set_rejects_global_definition_with_workspace_binding(): void
    {
        // Construct a workspace-scoped binding whose definition is global.
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => null,
            'code' => 'custom_text_ws_binding',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::PlatformLibrary,
            'localized_labels' => ['uk' => 'Test'],
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        $variant = $this->variant;
        $binding = FieldBinding::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
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
            'sort_order' => 0,
            'status' => AttributeStatus::Active,
        ]);

        $this->expectException(FieldDefinitionWorkspaceMismatchException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $variant->id,
            $binding->id,
            'x',
        );
    }

    public function test_set_rejects_customer_target_type_with_typed_writer_exception(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'customer_dynamic_text',
            dataType: AttributeDataType::Text,
            objectType: FieldObjectType::Customer,
        );

        try {
            $this->writer->set(
                $this->workspace->id,
                FieldObjectType::Customer,
                123,
                $binding->id,
                'x',
            );
            $this->fail('Expected typed writer exception was not thrown.');
        } catch (FieldValueWriterException $exception) {
            $this->assertInstanceOf(UnsupportedFieldObjectTypeException::class, $exception);
        }
    }

    // ---------------------------------------------------------------
    // Fail-closed for unsupported datatypes
    // ---------------------------------------------------------------

    #[DataProvider('unsupportedDataTypeProvider')]
    public function test_set_rejects_non_generic_domain_owned_data_types(AttributeDataType $dataType): void
    {
        $variant = $this->variant;

        [, $binding] = $this->createDefinitionAndBinding(
            code: 'unsupported_'.$dataType->value.'_'.Str::lower(Str::random(6)),
            dataType: $dataType,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->expectException(UnsupportedFieldDataTypeException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $variant->id,
            $binding->id,
            '123',
        );
    }

    public function test_set_rejects_multi_value_definition(): void
    {
        $variant = $this->variant;

        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => null,
            'code' => 'custom_multi_value_test',
            'data_type' => AttributeDataType::Select,
            'scope' => AttributeScope::PlatformLibrary,
            'localized_labels' => ['uk' => 'MV'],
            'description' => null,
            'validation_rules' => [
                'options' => [
                    ['code' => 'a', 'labels' => ['uk' => 'A']],
                    ['code' => 'b', 'labels' => ['uk' => 'B']],
                ],
            ],
            'is_localizable' => false,
            'is_multi_value' => true,
            'status' => AttributeStatus::Active,
        ]);

        $binding = FieldBinding::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => null,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::ProductVariant,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 0,
            'status' => AttributeStatus::Active,
        ]);

        $this->expectException(MultiValueNotSupportedException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $variant->id,
            $binding->id,
            'a',
        );
    }

    public function test_set_rejects_definition_absent_for_existing_binding(): void
    {
        $this->markTestSkipped(
            'Cannot simulate orphaned binding on SQLite because the binding->definition FK is '
            .'cascadeOnDelete. The writer behavior for missing definition is exercised by every '
            .'other test, since resolveContext() always resolves the definition freshly.'
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function dynamicBinding(
        string $code,
        FieldObjectType $objectType,
        ?AttributeStorageType $storage = null,
    ): FieldBinding {
        $definition = FieldDefinition::withoutWorkspaceScope()
            ->where('code', $code)
            ->firstOrFail();

        $query = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', $objectType);

        if ($storage !== null) {
            $query->where('storage_type', $storage);
        }

        return $query->firstOrFail();
    }

    /**
     * @return array{0: FieldDefinition, 1: FieldBinding}
     */
    private function createDefinitionAndBinding(
        string $code,
        AttributeDataType $dataType,
        FieldObjectType $objectType,
        bool $isLocalizable = false,
        bool $isMultiValue = false,
        ?array $validationRules = null,
        ?string $workspaceId = null,
    ): array {
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'code' => $code,
            'data_type' => $dataType,
            'scope' => AttributeScope::PlatformLibrary,
            'localized_labels' => ['uk' => $code],
            'description' => null,
            'validation_rules' => $validationRules,
            'is_localizable' => $isLocalizable,
            'is_multi_value' => $isMultiValue,
            'status' => AttributeStatus::Active,
        ]);

        $binding = FieldBinding::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'field_definition_id' => $definition->id,
            'object_type' => $objectType,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 0,
            'status' => AttributeStatus::Active,
        ]);

        return [$definition, $binding];
    }

    /**
     * @return array<string, array{0: AttributeDataType}>
     */
    public static function unsupportedDataTypeProvider(): array
    {
        return [
            'money' => [AttributeDataType::Money],
            'image' => [AttributeDataType::Image],
            'computed' => [AttributeDataType::Computed],
        ];
    }

    /**
     * @return array<string, array{0: AttributeDataType}>
     */
    public static function numericTypeProvider(): array
    {
        return [
            'number' => [AttributeDataType::Number],
            'decimal' => [AttributeDataType::Decimal],
        ];
    }

    /**
     * @return array<string, array{0: AttributeDataType, 1: int|string, 2: string}>
     */
    public static function numericAcceptedPayloadProvider(): array
    {
        return [
            'number_int' => [AttributeDataType::Number, 12, '12.000000'],
            'number_fractional' => [AttributeDataType::Number, '12.500000', '12.500000'],
            'number_negative' => [AttributeDataType::Number, '-12.5', '-12.500000'],
            'number_precision_boundary' => [AttributeDataType::Number, '99999999999999.999999', '99999999999999.999999'],
            'number_scale_boundary' => [AttributeDataType::Number, '1.123456000', '1.123456'],
            'decimal_int' => [AttributeDataType::Decimal, 7, '7.000000'],
            'decimal_fractional' => [AttributeDataType::Decimal, '98.765432', '98.765432'],
            'decimal_negative' => [AttributeDataType::Decimal, '-0.500000', '-0.500000'],
            'decimal_precision_boundary' => [AttributeDataType::Decimal, '99999999999999.999999', '99999999999999.999999'],
            'decimal_scale_boundary' => [AttributeDataType::Decimal, '2.654321000', '2.654321'],
        ];
    }

    /**
     * @return array<string, array{0: AttributeDataType, 1: string, 2: int|string, 3: string}>
     */
    public static function numericCanonicalNoOpProvider(): array
    {
        return [
            'number_same_value' => [AttributeDataType::Number, '0012.340000', '12.34', '12.340000'],
            'decimal_same_value' => [AttributeDataType::Decimal, '0007.500000', '7.5', '7.500000'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidNumericPayloadProvider(): array
    {
        return [
            'nan' => ['NaN'],
            'infinity' => ['Infinity'],
            'garbage' => ['12abc'],
            'locale_comma' => ['1,23'],
            'empty' => [''],
            'precision_overflow' => ['100000000000000.000000'],
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidBooleanPayloadProvider(): array
    {
        return [
            'int_zero' => [0],
            'int_one' => [1],
            'string_zero' => ['0'],
            'string_one' => ['1'],
            'string_true' => ['true'],
            'string_false' => ['false'],
            'string_yes' => ['yes'],
            'string_no' => ['no'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidDatePayloadProvider(): array
    {
        return [
            'datetime' => ['2026-08-26T00:00:00Z'],
            'dotted' => ['26.08.2026'],
            'non_canonical' => ['2026-8-26'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validUrlProvider(): array
    {
        return [
            'https' => ['https://example.com/product'],
            'http' => ['http://example.com/catalog?sku=ABC'],
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidUrlPayloadProvider(): array
    {
        return [
            'relative' => ['/relative/path'],
            'missing_host' => ['https:///missing-host'],
            'unsupported_scheme' => ['ftp://example.com/file'],
            'non_string' => [['https://example.com']],
        ];
    }
}
