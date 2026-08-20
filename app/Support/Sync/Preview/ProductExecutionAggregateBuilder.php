<?php

namespace App\Support\Sync\Preview;

use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Exceptions\Pricing\PriceListConfigurationException;
use App\Exceptions\Pricing\PriceNotAvailableException;
use App\Models\FieldBinding;
use App\Models\Product;
use App\Models\ProductFieldValue;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\Resolution\PriceResolutionStatus;
use App\Services\Pricing\ResolvedPrice;
use Illuminate\Support\Collection;

class ProductExecutionAggregateBuilder
{
    public function __construct(
        private readonly PriceResolver $priceResolver,
    ) {}

    /**
     * @param  list<string>  $productIds
     * @param  array<string, mixed>  $configurationSnapshot
     * @return list<ProductExecutionAggregate>
     */
    public function buildForProductIds(string $workspaceId, array $productIds, array $configurationSnapshot): array
    {
        if ($productIds === []) {
            return [];
        }

        $bindingIds = $this->extractMappedBindingIds($configurationSnapshot);

        $bindings = $bindingIds === []
            ? collect()
            : FieldBinding::withoutWorkspaceScope()
                ->with('fieldDefinition')
                ->where(function ($query) use ($workspaceId): void {
                    $query->whereNull('workspace_id')
                        ->orWhere('workspace_id', $workspaceId);
                })
                ->whereIn('id', $bindingIds)
                ->get()
                ->keyBy('id');

        $products = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $productIds)
            ->with(['variants' => static fn ($query) => $query->where('is_active', true)])
            ->orderBy('id')
            ->get();

        $loadedProductIds = $products->pluck('id')->all();
        $variantIds = $products->flatMap(static fn (Product $product) => $product->variants->pluck('id'))->all();

        $productFieldValues = $bindingIds === []
            ? collect()
            : ProductFieldValue::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->whereIn('product_id', $loadedProductIds)
                ->whereIn('field_binding_id', $bindingIds)
                ->get()
                ->groupBy('product_id');

        $variantFieldValues = $bindingIds === []
            ? collect()
            : VariantFieldValue::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->whereIn('variant_id', $variantIds)
                ->whereIn('field_binding_id', $bindingIds)
                ->get()
                ->groupBy('variant_id');

        return $products
            ->map(function (Product $product) use ($bindings, $productFieldValues, $variantFieldValues): ProductExecutionAggregate {
                $productValues = [];

                foreach ($bindings as $bindingId => $binding) {
                    if ($binding->object_type !== FieldObjectType::Product) {
                        continue;
                    }

                    $mapped = $this->resolveMappedFieldValue(
                        $binding,
                        $product,
                        null,
                        $productFieldValues->get($product->id, collect()),
                    );

                    if ($mapped !== null) {
                        $productValues[$bindingId] = $mapped;
                    }
                }

                $sellableVariants = $product->variants;
                $variantSlices = [];

                foreach ($sellableVariants as $variant) {
                    $values = [];

                    foreach ($bindings as $bindingId => $binding) {
                        if ($binding->object_type !== FieldObjectType::ProductVariant) {
                            continue;
                        }

                        $mapped = $this->resolveMappedFieldValue(
                            $binding,
                            $product,
                            $variant,
                            $variantFieldValues->get($variant->id, collect()),
                        );

                        if ($mapped !== null) {
                            $values[$bindingId] = $mapped;
                        }
                    }

                    [$resolvedPrice, $priceStatus] = $this->resolvePrice($variant);

                    $variantSlices[] = new ProductVariantExecutionSlice(
                        variantId: (string) $variant->id,
                        values: $values,
                        resolvedPrice: $resolvedPrice,
                        priceResolutionStatus: $priceStatus,
                    );
                }

                if ($sellableVariants->isEmpty()) {
                    foreach ($bindings as $bindingId => $binding) {
                        if ($binding->object_type !== FieldObjectType::ProductVariant) {
                            continue;
                        }

                        if (isset($productValues[$bindingId])) {
                            continue;
                        }

                        $mapped = $this->resolveMappedFieldValue(
                            $binding,
                            $product,
                            null,
                            collect(),
                        );

                        if ($mapped !== null) {
                            $productValues[$bindingId] = $mapped;
                        }
                    }
                }

                return new ProductExecutionAggregate(
                    productId: (string) $product->id,
                    productValues: $productValues,
                    variants: $variantSlices,
                    sellableVariantCount: $sellableVariants->count(),
                    imageInput: $this->buildImageInput($product),
                );
            })
            ->values()
            ->all();
    }

    private function buildImageInput(Product $product): ProductExecutionImageInput
    {
        $rawImages = $product->images;

        if ($rawImages === null) {
            return new ProductExecutionImageInput(ProductExecutionImageStructuralState::Valid, []);
        }

        if (! is_array($rawImages)) {
            return new ProductExecutionImageInput(ProductExecutionImageStructuralState::Malformed, []);
        }

        $entries = [];

        foreach (array_values($rawImages) as $index => $value) {
            if (! is_string($value) || trim($value) === '') {
                $entries[] = new ProductExecutionImageSourceEntry(
                    declarationIndex: (int) $index,
                    sourceReference: null,
                    isMalformed: true,
                );

                continue;
            }

            $entries[] = new ProductExecutionImageSourceEntry(
                declarationIndex: (int) $index,
                sourceReference: $value,
            );
        }

        return new ProductExecutionImageInput(ProductExecutionImageStructuralState::Valid, $entries);
    }

    /**
     * @param  array<string, mixed>  $configurationSnapshot
     * @return list<string>
     */
    private function extractMappedBindingIds(array $configurationSnapshot): array
    {
        /** @var list<array<string, mixed>> $fieldMappings */
        $fieldMappings = $configurationSnapshot['field_mappings'] ?? [];
        $bindingIds = [];

        foreach ($fieldMappings as $mapping) {
            $bindingId = $mapping['field_binding_id'] ?? null;

            if (is_string($bindingId) && $bindingId !== '') {
                $bindingIds[] = $bindingId;
            }
        }

        return array_values(array_unique($bindingIds));
    }

    /**
     * @param  Collection<int, ProductFieldValue|VariantFieldValue>  $storedValues
     */
    private function resolveMappedFieldValue(
        FieldBinding $binding,
        Product $product,
        ?ProductVariant $variant,
        Collection $storedValues,
    ): ?MappedFieldValue {
        $definition = $binding->fieldDefinition;

        if ($definition === null) {
            return null;
        }

        $value = match ($binding->storage_type) {
            AttributeStorageType::Column => $this->resolveColumnValue($binding, $product, $variant),
            AttributeStorageType::Dynamic => $this->resolveDynamicValue($binding, $storedValues),
            AttributeStorageType::Relation => $this->resolveRelationValue($binding, $product, $variant),
        };

        return new MappedFieldValue(
            fieldBindingId: (string) $binding->id,
            internalCode: (string) $definition->code,
            objectType: $binding->object_type,
            dataType: $definition->data_type,
            isRequired: (bool) $binding->is_required,
            isMultiValue: (bool) $definition->is_multi_value,
            value: $value,
        );
    }

    private function resolveColumnValue(
        FieldBinding $binding,
        Product $product,
        ?ProductVariant $variant,
    ): mixed {
        $storagePath = $binding->storage_path;

        if (! is_string($storagePath) || $storagePath === '') {
            return null;
        }

        [$table, $column] = array_pad(explode('.', $storagePath, 2), 2, null);

        if ($table === 'products') {
            return $product->getAttribute($column);
        }

        if ($table === 'product_variants') {
            if ($variant !== null) {
                return $variant->getAttribute($column);
            }
        }

        return null;
    }

    private function resolveRelationValue(
        FieldBinding $binding,
        Product $product,
        ?ProductVariant $variant,
    ): mixed {
        return $this->resolveColumnValue($binding, $product, $variant);
    }

    /**
     * @param  Collection<int, ProductFieldValue|VariantFieldValue>  $storedValues
     */
    private function resolveDynamicValue(FieldBinding $binding, Collection $storedValues): mixed
    {
        $row = $storedValues->firstWhere('field_binding_id', $binding->id);

        if ($row === null) {
            return null;
        }

        if ($row->value_text !== null) {
            return $row->value_text;
        }

        if ($row->value_num !== null) {
            return $row->value_num;
        }

        if ($row->value_jsonb !== null) {
            return $row->value_jsonb;
        }

        return null;
    }

    /**
     * @return array{0: ?ResolvedPrice, 1: ?string}
     */
    private function resolvePrice(ProductVariant $variant): array
    {
        try {
            return [$this->priceResolver->resolveDefault($variant), PriceResolutionStatus::Resolved->value];
        } catch (PriceNotAvailableException) {
            return [null, PriceResolutionStatus::Unavailable->value];
        } catch (PriceListConfigurationException) {
            return [null, PriceResolutionStatus::ConfigurationError->value];
        }
    }
}
