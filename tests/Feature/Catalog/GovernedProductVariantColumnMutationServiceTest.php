<?php

namespace Tests\Feature\Catalog;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Exceptions\Catalog\ColumnFieldClearRejectedException;
use App\Exceptions\Catalog\ColumnFieldNotAllowlistedException;
use App\Exceptions\Catalog\InvalidColumnFieldValueException;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use App\Services\Catalog\ColumnMutationResult;
use App\Services\Catalog\GovernedProductVariantColumnMutationService;
use App\Services\Fields\Exceptions\FieldBindingArchivedException;
use App\Services\Fields\Exceptions\FieldBindingObjectTypeMismatchException;
use App\Services\Fields\Exceptions\FieldBindingStorageTypeMismatchException;
use App\Services\Fields\Exceptions\FieldDefinitionArchivedException;
use App\Services\Fields\Exceptions\FieldDefinitionWorkspaceMismatchException;
use App\Services\Fields\Exceptions\TargetNotFoundException;
use App\Services\Fields\Exceptions\TargetWorkspaceMismatchException;
use Database\Seeders\FieldDefinitionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GovernedProductVariantColumnMutationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Workspace $foreignWorkspace;

    private Product $product;

    private ProductVariant $variant;

    private GovernedProductVariantColumnMutationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(FieldDefinitionSeeder::class);

        $this->workspace = Workspace::query()->where('is_default', true)->firstOrFail();
        $this->foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign Workspace',
            'is_default' => false,
        ]);
        $this->service = app(GovernedProductVariantColumnMutationService::class);

        $this->product = Product::query()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'GAP-029-PRODUCT-001',
            'name' => 'Initial Product Name',
            'description' => 'Initial description',
            'unit' => 'шт',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::query()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $this->product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'GAP-029-VARIANT-001',
            'is_active' => true,
        ]);
    }

    public function test_set_name_updates_product_column(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);

        $result = $this->service->set(
            workspaceId: $this->workspace->id,
            targetType: FieldObjectType::Product,
            targetId: $this->product->id,
            fieldBindingId: $binding->id,
            value: 'Updated Product Name',
        );

        $this->assertSame(ColumnMutationResult::Updated, $result->status);
        $this->assertSame($binding->id, $result->fieldBindingId);
        $this->assertSame('Updated Product Name', $this->product->fresh()->name);
    }

    public function test_set_identical_name_is_noop(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        $updatedAtBefore = $this->product->updated_at->toAtomString();

        $result = $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
            'Initial Product Name',
        );

        $this->assertSame(ColumnMutationResult::NoOp, $result->status);
        $this->assertFalse($result->isMutation());
        $this->assertSame($updatedAtBefore, $this->product->fresh()->updated_at->toAtomString());
    }

    public function test_set_name_null_is_rejected_and_does_not_mutate(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        $snapshot = $this->productSnapshot();

        $this->expectException(InvalidColumnFieldValueException::class);

        try {
            $this->service->set(
                $this->workspace->id,
                FieldObjectType::Product,
                $this->product->id,
                $binding->id,
                null,
            );
        } finally {
            $this->assertProductSnapshotUnchanged($snapshot);
        }
    }

    public function test_set_name_empty_string_is_rejected_and_does_not_mutate(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        $snapshot = $this->productSnapshot();

        $this->expectException(InvalidColumnFieldValueException::class);

        try {
            $this->service->set(
                $this->workspace->id,
                FieldObjectType::Product,
                $this->product->id,
                $binding->id,
                '',
            );
        } finally {
            $this->assertProductSnapshotUnchanged($snapshot);
        }
    }

    public function test_set_name_whitespace_only_is_rejected_and_does_not_mutate(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        $snapshot = $this->productSnapshot();

        $this->expectException(InvalidColumnFieldValueException::class);

        try {
            $this->service->set(
                $this->workspace->id,
                FieldObjectType::Product,
                $this->product->id,
                $binding->id,
                " \t\n ",
            );
        } finally {
            $this->assertProductSnapshotUnchanged($snapshot);
        }
    }

    public function test_set_name_oversize_is_rejected_and_does_not_mutate(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        $snapshot = $this->productSnapshot();

        $this->expectException(InvalidColumnFieldValueException::class);

        try {
            $this->service->set(
                $this->workspace->id,
                FieldObjectType::Product,
                $this->product->id,
                $binding->id,
                str_repeat('N', 256),
            );
        } finally {
            $this->assertProductSnapshotUnchanged($snapshot);
        }
    }

    public function test_clear_name_is_rejected_and_does_not_mutate(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        $snapshot = $this->productSnapshot();

        $this->expectException(ColumnFieldClearRejectedException::class);

        try {
            $this->service->clear(
                $this->workspace->id,
                FieldObjectType::Product,
                $this->product->id,
                $binding->id,
            );
        } finally {
            $this->assertProductSnapshotUnchanged($snapshot);
        }
    }

    public function test_set_description_updates_product_column(): void
    {
        $binding = $this->columnBinding('description', FieldObjectType::Product);

        $result = $this->service->set(
            workspaceId: $this->workspace->id,
            targetType: FieldObjectType::Product,
            targetId: $this->product->id,
            fieldBindingId: $binding->id,
            value: 'Updated description body',
        );

        $this->assertSame(ColumnMutationResult::Updated, $result->status);
        $this->assertSame('Updated description body', $this->product->fresh()->description);
    }

    public function test_set_identical_description_is_noop(): void
    {
        $binding = $this->columnBinding('description', FieldObjectType::Product);
        $updatedAtBefore = $this->product->updated_at->toAtomString();

        $result = $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
            'Initial description',
        );

        $this->assertSame(ColumnMutationResult::NoOp, $result->status);
        $this->assertSame($updatedAtBefore, $this->product->fresh()->updated_at->toAtomString());
    }

    public function test_set_empty_description_is_a_legitimate_explicit_value(): void
    {
        $binding = $this->columnBinding('description', FieldObjectType::Product);

        $result = $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
            '',
        );

        $this->assertSame(ColumnMutationResult::Updated, $result->status);
        $this->assertSame('', $this->product->fresh()->description);
    }

    public function test_clear_description_sets_null(): void
    {
        $binding = $this->columnBinding('description', FieldObjectType::Product);

        $result = $this->service->clear(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
        );

        $this->assertSame(ColumnMutationResult::Updated, $result->status);
        $this->assertNull($this->product->fresh()->description);
    }

    public function test_clear_description_when_already_null_is_noop(): void
    {
        $binding = $this->columnBinding('description', FieldObjectType::Product);
        $this->product->description = null;
        $this->product->save();
        $updatedAtBefore = $this->product->fresh()->updated_at->toAtomString();

        $result = $this->service->clear(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
        );

        $this->assertSame(ColumnMutationResult::NoOp, $result->status);
        $this->assertSame($updatedAtBefore, $this->product->fresh()->updated_at->toAtomString());
    }

    public function test_set_description_null_is_rejected_and_does_not_mutate(): void
    {
        $binding = $this->columnBinding('description', FieldObjectType::Product);
        $snapshot = $this->productSnapshot();

        $this->expectException(InvalidColumnFieldValueException::class);

        try {
            $this->service->set(
                $this->workspace->id,
                FieldObjectType::Product,
                $this->product->id,
                $binding->id,
                null,
            );
        } finally {
            $this->assertProductSnapshotUnchanged($snapshot);
        }
    }

    public function test_foreign_workspace_target_is_rejected(): void
    {
        $foreignProduct = Product::query()->create([
            'workspace_id' => $this->foreignWorkspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'FOREIGN-PRODUCT-001',
            'name' => 'Foreign Product',
            'unit' => 'шт',
            'is_active' => true,
        ]);
        $binding = $this->columnBinding('name', FieldObjectType::Product);

        $this->expectException(TargetWorkspaceMismatchException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $foreignProduct->id,
            $binding->id,
            'Should Fail',
        );
    }

    public function test_missing_target_is_rejected(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);

        $this->expectException(TargetNotFoundException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            999999,
            $binding->id,
            'Missing',
        );
    }

    public function test_inactive_binding_is_rejected(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        FieldBinding::withoutWorkspaceScope()->whereKey($binding->id)->update([
            'status' => AttributeStatus::Archived->value,
        ]);

        $this->expectException(FieldBindingArchivedException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
            'Blocked',
        );
    }

    public function test_inactive_definition_is_rejected(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        FieldDefinition::withoutWorkspaceScope()
            ->whereKey($binding->field_definition_id)
            ->update(['status' => AttributeStatus::Archived->value]);

        $this->expectException(FieldDefinitionArchivedException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
            'Blocked',
        );
    }

    public function test_dynamic_binding_is_rejected(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        FieldBinding::withoutWorkspaceScope()->whereKey($binding->id)->update([
            'storage_type' => AttributeStorageType::Dynamic->value,
        ]);

        $this->expectException(FieldBindingStorageTypeMismatchException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
            'Blocked',
        );
    }

    public function test_relation_binding_is_rejected(): void
    {
        $binding = $this->bindingByCodeAndObjectType('category', FieldObjectType::Product);

        $this->expectException(FieldBindingStorageTypeMismatchException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
            'Blocked',
        );
    }

    public function test_wrong_object_type_is_rejected(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);

        $this->expectException(FieldBindingObjectTypeMismatchException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            'Blocked',
        );
    }

    #[DataProvider('variantColumnCodesProvider')]
    public function test_product_variant_column_binding_is_fail_closed_in_first_allowlist(string $code): void
    {
        $binding = $this->columnBinding($code, FieldObjectType::ProductVariant);

        $this->expectException(ColumnFieldNotAllowlistedException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::ProductVariant,
            $this->variant->id,
            $binding->id,
            'Blocked',
        );
    }

    public function test_wrong_datatype_is_rejected_even_for_canonical_name_binding(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        FieldDefinition::withoutWorkspaceScope()
            ->whereKey($binding->field_definition_id)
            ->update(['data_type' => AttributeDataType::LongText->value]);

        $this->expectException(ColumnFieldNotAllowlistedException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
            'Blocked',
        );
    }

    public function test_wrong_definition_code_with_correct_products_name_path_is_rejected(): void
    {
        $binding = $this->createBindingForDefinition($this->createDefinition([
            'workspace_id' => null,
            'scope' => AttributeScope::System,
            'code' => 'display_name',
            'data_type' => AttributeDataType::Text,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]), [
            'workspace_id' => null,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Column,
            'storage_path' => 'products.name',
            'status' => AttributeStatus::Active,
        ]);

        $this->expectException(ColumnFieldNotAllowlistedException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
            'Blocked',
        );
    }

    public function test_workspace_custom_definition_and_binding_for_products_name_are_rejected(): void
    {
        $binding = $this->createBindingForDefinition($this->createDefinition([
            'workspace_id' => $this->workspace->id,
            'scope' => AttributeScope::WorkspaceCustom,
            'code' => 'workspace_name',
            'data_type' => AttributeDataType::Text,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]), [
            'workspace_id' => $this->workspace->id,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Column,
            'storage_path' => 'products.name',
            'status' => AttributeStatus::Active,
        ]);

        $this->expectException(ColumnFieldNotAllowlistedException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
            'Blocked',
        );
    }

    public function test_forged_storage_path_is_rejected(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        FieldBinding::withoutWorkspaceScope()->whereKey($binding->id)->update([
            'storage_path' => 'products.brand',
        ]);

        $this->expectException(ColumnFieldNotAllowlistedException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
            'Blocked',
        );
    }

    public function test_mismatched_definition_and_binding_ownership_is_rejected(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        FieldDefinition::withoutWorkspaceScope()
            ->whereKey($binding->field_definition_id)
            ->update(['workspace_id' => $this->workspace->id]);

        $this->expectException(FieldDefinitionWorkspaceMismatchException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
            'Blocked',
        );
    }

    public function test_unknown_validation_rules_fail_closed(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        FieldDefinition::withoutWorkspaceScope()
            ->whereKey($binding->field_definition_id)
            ->update(['validation_rules' => json_encode(['max' => 255], JSON_THROW_ON_ERROR)]);

        $this->expectException(ColumnFieldNotAllowlistedException::class);

        $this->service->set(
            $this->workspace->id,
            FieldObjectType::Product,
            $this->product->id,
            $binding->id,
            'Blocked',
        );
    }

    public function test_stale_preflight_metadata_cannot_authorize_after_definition_and_binding_change(): void
    {
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        $staleBinding = FieldBinding::withoutWorkspaceScope()->findOrFail($binding->id);
        $staleDefinition = FieldDefinition::withoutWorkspaceScope()->findOrFail($binding->field_definition_id);
        $snapshot = $this->productSnapshot();

        FieldDefinition::withoutWorkspaceScope()
            ->whereKey($binding->field_definition_id)
            ->update(['code' => 'display_name']);
        FieldBinding::withoutWorkspaceScope()
            ->whereKey($binding->id)
            ->update(['storage_path' => 'products.brand']);

        $this->assertSame('name', $staleDefinition->code);
        $this->assertSame('products.name', $staleBinding->storage_path);
        $this->expectException(ColumnFieldNotAllowlistedException::class);

        try {
            $this->service->set(
                $this->workspace->id,
                FieldObjectType::Product,
                $this->product->id,
                $binding->id,
                'Blocked',
            );
        } finally {
            $this->assertProductSnapshotUnchanged($snapshot);
        }
    }

    #[DataProvider('rejectedSeededColumnBindingProvider')]
    public function test_non_allowlisted_seeded_column_bindings_remain_fail_closed(string $code, FieldObjectType $objectType): void
    {
        $binding = $this->columnBinding($code, $objectType);
        $targetId = $objectType === FieldObjectType::Product ? $this->product->id : $this->variant->id;
        $payload = $objectType === FieldObjectType::ProductVariant ? 'Blocked' : 'Blocked';

        $this->expectException(ColumnFieldNotAllowlistedException::class);

        $this->service->set(
            $this->workspace->id,
            $objectType,
            $targetId,
            $binding->id,
            $payload,
        );
    }

    public function test_public_api_exposes_field_binding_not_raw_column_parameters_and_avoids_mass_assignment_calls(): void
    {
        $set = new \ReflectionMethod(GovernedProductVariantColumnMutationService::class, 'set');
        $clear = new \ReflectionMethod(GovernedProductVariantColumnMutationService::class, 'clear');

        $this->assertSame(
            ['workspaceId', 'targetType', 'targetId', 'fieldBindingId', 'value'],
            array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $set->getParameters()),
        );
        $this->assertSame(
            ['workspaceId', 'targetType', 'targetId', 'fieldBindingId'],
            array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $clear->getParameters()),
        );

        $source = File::get(base_path('app/Services/Catalog/GovernedProductVariantColumnMutationService.php'));

        $this->assertStringNotContainsString('fill(', $source);
        $this->assertStringNotContainsString('forceFill(', $source);
        $this->assertDoesNotMatchRegularExpression('/->update\s*\(/', $source);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function variantColumnCodesProvider(): array
    {
        return [
            'sku' => ['sku'],
            'gtin' => ['gtin'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: FieldObjectType}>
     */
    public static function rejectedSeededColumnBindingProvider(): array
    {
        return [
            'brand' => ['brand', FieldObjectType::Product],
            'url' => ['url', FieldObjectType::Product],
            'merchant_type' => ['merchant_type', FieldObjectType::Product],
            'net_weight' => ['net_weight', FieldObjectType::Product],
            'gross_weight' => ['gross_weight', FieldObjectType::Product],
            'volume_m3' => ['volume_m3', FieldObjectType::Product],
            'status' => ['status', FieldObjectType::Product],
            'internal_product_id' => ['internal_product_id', FieldObjectType::Product],
        ];
    }

    private function columnBinding(string $code, FieldObjectType $objectType): FieldBinding
    {
        return $this->bindingByCodeAndObjectType($code, $objectType, AttributeStorageType::Column);
    }

    private function bindingByCodeAndObjectType(
        string $code,
        FieldObjectType $objectType,
        ?AttributeStorageType $storageType = null,
    ): FieldBinding {
        $query = FieldBinding::withoutWorkspaceScope()
            ->where('object_type', $objectType->value)
            ->whereIn('field_definition_id', FieldDefinition::withoutWorkspaceScope()
                ->where('code', $code)
                ->pluck('id'));

        if ($storageType !== null) {
            $query->where('storage_type', $storageType->value);
        }

        return $query->firstOrFail();
    }

    /**
     * @param  array{
     *   workspace_id: string|null,
     *   scope: AttributeScope,
     *   code: string,
     *   data_type: AttributeDataType,
     *   validation_rules: array<mixed>|null,
     *   is_localizable: bool,
     *   is_multi_value: bool,
     *   status: AttributeStatus
     * }  $attributes
     */
    private function createDefinition(array $attributes): FieldDefinition
    {
        return FieldDefinition::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $attributes['workspace_id'],
            'scope' => $attributes['scope'],
            'code' => $attributes['code'],
            'data_type' => $attributes['data_type'],
            'localized_labels' => ['uk' => $attributes['code']],
            'description' => null,
            'validation_rules' => $attributes['validation_rules'],
            'is_localizable' => $attributes['is_localizable'],
            'is_multi_value' => $attributes['is_multi_value'],
            'status' => $attributes['status'],
        ]);
    }

    /**
     * @param  array{
     *   workspace_id: string|null,
     *   object_type: FieldObjectType,
     *   storage_type: AttributeStorageType,
     *   storage_path: string|null,
     *   status: AttributeStatus
     * }  $attributes
     */
    private function createBindingForDefinition(FieldDefinition $definition, array $attributes): FieldBinding
    {
        return FieldBinding::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $attributes['workspace_id'],
            'field_definition_id' => $definition->id,
            'object_type' => $attributes['object_type'],
            'storage_type' => $attributes['storage_type'],
            'storage_path' => $attributes['storage_path'],
            'field_group' => 'basic_information',
            'is_required' => false,
            'is_filterable' => true,
            'is_sortable' => true,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 0,
            'status' => $attributes['status'],
        ]);
    }

    /**
     * @return array{name: string, description: ?string, updated_at: string}
     */
    private function productSnapshot(): array
    {
        $product = $this->product->fresh();

        return [
            'name' => $product->name,
            'description' => $product->description,
            'updated_at' => $product->updated_at->toAtomString(),
        ];
    }

    /**
     * @param  array{name: string, description: ?string, updated_at: string}  $snapshot
     */
    private function assertProductSnapshotUnchanged(array $snapshot): void
    {
        $product = $this->product->fresh();

        $this->assertSame($snapshot['name'], $product->name);
        $this->assertSame($snapshot['description'], $product->description);
        $this->assertSame($snapshot['updated_at'], $product->updated_at->toAtomString());
    }
}
