<?php

namespace App\Services\ProductStructure;

use App\Enums\AttributeDataType;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductVariant;
use App\Services\Catalog\GovernedProductVariantColumnMutationService;
use App\Services\Fields\GovernedDynamicFieldValueWriter;
use App\Support\ProductStructure\Exceptions\ProductStructureInvariantException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

final class ProductVariantBulkValueMutationService
{
    public function __construct(
        private readonly GovernedDynamicFieldValueWriter $dynamicWriter,
        private readonly GovernedProductVariantColumnMutationService $columnWriter,
    ) {}

    /**
     * @param  list<int>  $variantIds
     * @return array{succeeded_variant_ids:list<int>,failed:list<array{variant_id:int,error_class:string}>}
     */
    public function apply(
        Product $product,
        FieldBinding $binding,
        array $variantIds,
        mixed $value,
        bool $clear = false,
        ?string $locale = null,
    ): array {
        $product = Product::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->whereKey($product->id)
            ->firstOrFail();
        $binding = FieldBinding::withoutWorkspaceScope()->with('fieldDefinition')->findOrFail($binding->id);
        $definition = $binding->fieldDefinition;

        if (! $definition instanceof FieldDefinition
            || $binding->object_type !== FieldObjectType::ProductVariant
            || $binding->status !== AttributeStatus::Active
            || $definition->status !== AttributeStatus::Active
            || ($binding->workspace_id !== null && (string) $binding->workspace_id !== (string) $product->workspace_id)
            || ($definition->workspace_id ?? null) !== ($binding->workspace_id ?? null)) {
            throw new ProductStructureInvariantException('Variant bulk field must be an active admitted ProductVariant binding.');
        }

        $admitted = ProductTypeFieldPlacement::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_type_id', $product->product_type_id)
            ->where('field_binding_id', $binding->id)
            ->exists();
        if (! $admitted) {
            throw new ProductStructureInvariantException('Variant bulk field is not admitted by the Product current ProductType.');
        }

        $ids = array_values(array_unique(array_map('intval', $variantIds)));
        sort($ids);
        $succeeded = [];
        $failed = [];

        foreach ($ids as $variantId) {
            try {
                $freshProductTypeId = Product::withoutWorkspaceScope()
                    ->where('workspace_id', $product->workspace_id)
                    ->whereKey($product->id)
                    ->value('product_type_id');
                if ((string) $freshProductTypeId !== (string) $product->product_type_id) {
                    throw new ProductStructureInvariantException('ProductType changed during Variant bulk mutation.');
                }

                $variant = ProductVariant::withoutWorkspaceScope()
                    ->where('workspace_id', $product->workspace_id)
                    ->where('product_id', $product->id)
                    ->where('is_active', true)
                    ->whereKey($variantId)
                    ->first();
                if (! $variant instanceof ProductVariant) {
                    throw (new ModelNotFoundException)->setModel(ProductVariant::class, [$variantId]);
                }

                if ($binding->storage_type === AttributeStorageType::Dynamic) {
                    if ($clear) {
                        $this->dynamicWriter->clear(
                            (string) $product->workspace_id,
                            FieldObjectType::ProductVariant,
                            $variant->id,
                            (string) $binding->id,
                            $definition->is_localizable ? $locale : null,
                        );
                    } else {
                        $this->dynamicWriter->set(
                            (string) $product->workspace_id,
                            FieldObjectType::ProductVariant,
                            $variant->id,
                            (string) $binding->id,
                            $value,
                            $definition->is_localizable ? $locale : null,
                        );
                    }
                } elseif ($binding->storage_type === AttributeStorageType::Column) {
                    if ($clear) {
                        $this->columnWriter->clear(
                            (string) $product->workspace_id,
                            FieldObjectType::ProductVariant,
                            $variant->id,
                            (string) $binding->id,
                        );
                    } else {
                        $this->columnWriter->set(
                            (string) $product->workspace_id,
                            FieldObjectType::ProductVariant,
                            $variant->id,
                            (string) $binding->id,
                            $value,
                        );
                    }
                } else {
                    throw new ProductStructureInvariantException('Variant bulk field storage is not governed for editing.');
                }

                $succeeded[] = $variantId;
            } catch (Throwable $exception) {
                $failed[] = [
                    'variant_id' => $variantId,
                    'error_class' => $exception::class,
                ];
            }
        }

        return [
            'succeeded_variant_ids' => $succeeded,
            'failed' => $failed,
        ];
    }

    public function coerceTextInput(FieldDefinition $definition, ?string $input): mixed
    {
        if ($input === null) {
            return null;
        }

        return match ($definition->data_type) {
            AttributeDataType::Boolean => match (mb_strtolower(trim($input))) {
                '1', 'true', 'yes', 'так' => true,
                '0', 'false', 'no', 'ні' => false,
                default => throw new ProductStructureInvariantException('Boolean bulk value must be true/false, 1/0, yes/no or так/ні.'),
            },
            AttributeDataType::MultiSelect => array_values(array_filter(
                array_map('trim', explode(',', $input)),
                fn (string $value): bool => $value !== '',
            )),
            AttributeDataType::Text,
            AttributeDataType::LongText,
            AttributeDataType::Number,
            AttributeDataType::Decimal,
            AttributeDataType::Date,
            AttributeDataType::Select,
            AttributeDataType::Url => $input,
            default => throw new ProductStructureInvariantException('This field type is not supported by deterministic Variant bulk editing.'),
        };
    }
}
