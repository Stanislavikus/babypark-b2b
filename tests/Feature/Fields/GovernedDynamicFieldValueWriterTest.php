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
    // GAP-028B typed storage extension
    // ---------------------------------------------------------------

    #[DataProvider('numberAcceptedPayloadProvider')]
    public function test_set_number_accepts_int_and_canonical_integer_string(
        int|string $payload,
        string $expectedValueNum,
    ): void {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'number_accept_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Number,
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

    #[DataProvider('invalidNumberPayloadProvider')]
    public function test_set_number_rejects_fractional_float_exponent_and_malformed_payloads(mixed $payload): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'number_invalid_'.Str::lower(Str::random(6)),
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

    public function test_set_number_rejects_out_of_range_value(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'number_range_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Number,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->expectException(InvalidFieldValuePayloadException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            '100000000000000',
        );
    }

    public function test_set_number_same_canonical_value_is_noop(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'number_noop_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Number,
            objectType: FieldObjectType::ProductVariant,
        );

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            '12',
        );

        $rowBefore = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            12,
        );

        $this->assertSame(FieldValueWriteResult::NoOp, $result->status);

        $rowAfter = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertSame($rowBefore->updated_at->toAtomString(), $rowAfter->updated_at->toAtomString());
    }

    public function test_set_number_repairs_stale_other_payload_columns(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'number_stale_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Number,
            objectType: FieldObjectType::ProductVariant,
        );

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'variant_id' => $this->variant->id,
            'field_binding_id' => $binding->id,
            'value_text' => '12',
            'value_num' => '12.000000',
            'value_jsonb' => ['legacy'],
        ]);

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            12,
        );

        $this->assertSame(FieldValueWriteResult::Updated, $result->status);

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertNull($row->value_text);
        $this->assertSame('12.000000', $row->value_num);
        $this->assertNull($row->value_jsonb);
    }

    #[DataProvider('decimalAcceptedPayloadProvider')]
    public function test_set_decimal_accepts_exact_decimal_payloads(
        int|string $payload,
        string $expectedValueNum,
    ): void {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'decimal_accept_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Decimal,
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

        $this->assertNull($row->value_text);
        $this->assertSame($expectedValueNum, $row->value_num);
        $this->assertNull($row->value_jsonb);
    }

    #[DataProvider('invalidDecimalPayloadProvider')]
    public function test_set_decimal_rejects_invalid_payloads(mixed $payload): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'decimal_invalid_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Decimal,
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

    public function test_set_decimal_repairs_stale_other_payload_columns(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'decimal_stale_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Decimal,
            objectType: FieldObjectType::ProductVariant,
        );

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'variant_id' => $this->variant->id,
            'field_binding_id' => $binding->id,
            'value_text' => '12.5',
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

    public function test_set_boolean_true_and_false_use_value_num_only(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'boolean_values_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Boolean,
            objectType: FieldObjectType::ProductVariant,
        );

        $created = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            true,
        );

        $this->assertSame(FieldValueWriteResult::Created, $created->status);

        $updated = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            false,
        );

        $this->assertSame(FieldValueWriteResult::Updated, $updated->status);

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

    public function test_set_boolean_same_canonical_value_is_noop_and_stale_payload_is_repaired(): void
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

        $noOp = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            true,
        );

        $this->assertSame(FieldValueWriteResult::NoOp, $noOp->status);

        VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->update([
                'value_text' => 'legacy',
                'value_jsonb' => ['legacy' => true],
            ]);

        $updated = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            true,
        );

        $this->assertSame(FieldValueWriteResult::Updated, $updated->status);
    }

    public function test_set_date_accepts_real_calendar_dates_and_uses_value_text_only(): void
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

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            '2024-02-29',
        );

        $row = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('variant_id', $this->variant->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $this->assertSame('2024-02-29', $row->value_text);
        $this->assertNull($row->value_num);
        $this->assertNull($row->value_jsonb);
    }

    #[DataProvider('invalidDatePayloadProvider')]
    public function test_set_date_rejects_invalid_date_payloads(string $payload): void
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

    public function test_set_date_repairs_stale_numeric_payload_column(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'date_stale_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Date,
            objectType: FieldObjectType::ProductVariant,
        );

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'variant_id' => $this->variant->id,
            'field_binding_id' => $binding->id,
            'value_text' => '2026-08-26',
            'value_num' => '1.000000',
            'value_jsonb' => null,
        ]);

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            '2026-08-26',
        );

        $this->assertSame(FieldValueWriteResult::Updated, $result->status);
    }

    #[DataProvider('validUrlPayloadProvider')]
    public function test_set_url_accepts_valid_http_and_https_urls(string $payload): void
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
    public function test_set_url_rejects_invalid_payloads(mixed $payload): void
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

    public function test_set_url_repairs_stale_numeric_payload_column(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'url_stale_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Url,
            objectType: FieldObjectType::ProductVariant,
        );

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'variant_id' => $this->variant->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'https://example.com/product',
            'value_num' => '5.000000',
            'value_jsonb' => null,
        ]);

        $result = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            'https://example.com/product',
        );

        $this->assertSame(FieldValueWriteResult::Updated, $result->status);
    }

    public function test_set_multiselect_accepts_valid_codes_and_stores_lexical_canonical_order(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_valid_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: $this->multiSelectRules(),
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

    public function test_set_multiselect_different_input_order_is_canonical_noop(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_noop_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: $this->multiSelectRules(),
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

    #[DataProvider('invalidMultiSelectPayloadProvider')]
    public function test_set_multiselect_rejects_invalid_payload_shapes(mixed $payload): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_invalid_shape_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: $this->multiSelectRules(),
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

    public function test_set_multiselect_rejects_unknown_codes_and_labels(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_invalid_codes_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: $this->multiSelectRules(),
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

    public function test_set_multiselect_rejects_unknown_code(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_unknown_code_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: $this->multiSelectRules(),
        );

        $this->expectException(InvalidSelectOptionException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            ['blue', 'unknown'],
        );
    }

    public function test_set_multiselect_rejects_duplicate_codes(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_duplicate_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: $this->multiSelectRules(),
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

    public function test_set_multiselect_rejects_wrong_metadata_shape(): void
    {
        [, $wrongCardinality] = $this->createDefinitionAndBinding(
            code: 'multi_wrong_cardinality_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: false,
            validationRules: $this->multiSelectRules(),
        );

        $this->expectException(MultiValueNotSupportedException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $wrongCardinality->id,
            ['blue'],
        );
    }

    public function test_set_number_rejects_localizable_metadata(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'number_localizable_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Number,
            objectType: FieldObjectType::ProductVariant,
            isLocalizable: true,
        );

        $this->expectException(LocalizationContractViolationException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            12,
            'uk',
        );
    }

    public function test_set_rejects_unknown_number_validation_rules(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'number_with_unknown_rule_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::Number,
            objectType: FieldObjectType::ProductVariant,
            validationRules: ['min' => 1],
        );

        $this->expectException(UnsupportedFieldValidationRulesException::class);

        $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            1,
        );
    }

    public function test_set_rejects_unknown_multiselect_validation_rules(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_with_unknown_rule_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: [
                ...$this->multiSelectRules(),
                'max_items' => 2,
            ],
        );

        $this->expectException(UnsupportedFieldValidationRulesException::class);

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
            code: 'multi_localizable_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isLocalizable: true,
            isMultiValue: true,
            validationRules: $this->multiSelectRules(),
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

    public function test_set_multiselect_repairs_stale_scalar_columns_and_clear_deletes_row(): void
    {
        [, $binding] = $this->createDefinitionAndBinding(
            code: 'multi_stale_clear_'.Str::lower(Str::random(6)),
            dataType: AttributeDataType::MultiSelect,
            objectType: FieldObjectType::ProductVariant,
            isMultiValue: true,
            validationRules: $this->multiSelectRules(),
        );

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'variant_id' => $this->variant->id,
            'field_binding_id' => $binding->id,
            'value_text' => 'legacy',
            'value_num' => '1.000000',
            'value_jsonb' => ['pink', 'blue'],
        ]);

        $updated = $this->writer->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            ['blue', 'pink'],
        );

        $this->assertSame(FieldValueWriteResult::Updated, $updated->status);

        $deleted = $this->writer->clear(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
        );

        $this->assertSame(FieldValueWriteResult::Deleted, $deleted->status);
        $this->assertDatabaseMissing('variant_field_values', [
            'workspace_id' => $this->workspace->id,
            'variant_id' => $this->variant->id,
            'field_binding_id' => $binding->id,
        ]);
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

    private function multiSelectRules(): array
    {
        return [
            'options' => [
                ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                ['code' => 'pink', 'labels' => ['uk' => 'Рожевий']],
            ],
        ];
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
     * @return array<string, array{0: int|string, 1: string}>
     */
    public static function numberAcceptedPayloadProvider(): array
    {
        return [
            'int' => [12, '12.000000'],
            'integer_string' => ['123', '123.000000'],
            'negative' => ['-45', '-45.000000'],
            'max_boundary' => ['99999999999999', '99999999999999.000000'],
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidNumberPayloadProvider(): array
    {
        return [
            'fractional_string' => ['12.5'],
            'float' => [12.5],
            'exponent' => ['1e3'],
            'garbage' => ['12abc'],
            'non_canonical_leading_zero' => ['0012'],
        ];
    }

    /**
     * @return array<string, array{0: int|string, 1: string}>
     */
    public static function decimalAcceptedPayloadProvider(): array
    {
        return [
            'int' => [7, '7.000000'],
            'exact_decimal' => ['12.5', '12.500000'],
            'negative' => ['-0.125', '-0.125000'],
            'six_decimals' => ['1.123456', '1.123456'],
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidDecimalPayloadProvider(): array
    {
        return [
            'too_many_decimals' => ['1.1234567'],
            'float' => [1.5],
            'exponent' => ['1e3'],
            'precision_overflow' => ['100000000000000.000000'],
            'garbage' => ['12.3abc'],
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
            'string_true' => ['true'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidDatePayloadProvider(): array
    {
        return [
            'invalid_leap_day' => ['2026-02-29'],
            'datetime' => ['2026-08-26T00:00:00Z'],
            'alternate_format' => ['26.08.2026'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validUrlPayloadProvider(): array
    {
        return [
            'http' => ['http://example.com/catalog'],
            'https' => ['https://example.com/product?sku=ABC'],
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidUrlPayloadProvider(): array
    {
        return [
            'relative' => ['/relative/path'],
            'unsupported_scheme' => ['ftp://example.com/file'],
            'missing_host' => ['https:///missing-host'],
            'non_string' => [['https://example.com']],
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidMultiSelectPayloadProvider(): array
    {
        return [
            'empty_list' => [[]],
            'associative_array' => [['first' => 'blue']],
            'non_string_member' => [['blue', 1]],
        ];
    }
}
