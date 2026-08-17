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
     * @param  Collection<int, Product>  $products
     * @return list<ProductExecutionAggregate>
     */
    public function buildForProducts(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $workspaceId = (string) $products->first()->workspace_id;

        $bindings = FieldBinding::withoutWorkspaceScope()
            ->with('fieldDefinition')
            ->where(function ($query) use ($workspaceId): void {
                $query->whereNull('workspace_id')
                    ->orWhere('workspace_id', $workspaceId);
            })
            ->whereIn('object_type', [FieldObjectType::Product, FieldObjectType::ProductVariant])
            ->get()
            ->keyBy('id');

        $productIds = $products->pluck('id')->all();
        $variantIds = $products->flatMap(static fn (Product $product) => $product->variants->pluck('id'))->all();

        $productFieldValues = ProductFieldValue::withoutWorkspaceScope()
            ->whereIn('product_id', $productIds)
            ->get()
            ->groupBy('product_id');

        $variantFieldValues = VariantFieldValue::withoutWorkspaceScope()
            ->whereIn('variant_id', $variantIds)
            ->get()
            ->groupBy('variant_id');

        return $products
            ->map(function (Product $product) use ($bindings, $productFieldValues, $variantFieldValues): ProductExecutionAggregate {
                $productValues = [];

                foreach ($bindings as $bindingId => $binding) {
                    if ($binding->object_type !== FieldObjectType::Product) {
                        continue;
                    }

                    $value = $this->resolveBindingValue(
                        $binding,
                        $product,
                        null,
                        $productFieldValues->get($product->id, collect()),
                    );

                    if ($value !== null) {
                        $productValues[$bindingId] = $value;
                    }
                }

                $variants = $product->variants
                    ->map(function (ProductVariant $variant) use ($bindings, $variantFieldValues): ProductVariantExecutionSlice {
                        $values = [];

                        foreach ($bindings as $bindingId => $binding) {
                            if ($binding->object_type !== FieldObjectType::ProductVariant) {
                                continue;
                            }

                            $value = $this->resolveBindingValue(
                                $binding,
                                $variant->product,
                                $variant,
                                $variantFieldValues->get($variant->id, collect()),
                            );

                            if ($value !== null) {
                                $values[$bindingId] = $value;
                            }
                        }

                        [$resolvedPrice, $priceStatus] = $this->resolvePrice($variant);

                        return new ProductVariantExecutionSlice(
                            variantId: (string) $variant->id,
                            sku: (string) $variant->sku,
                            values: $values,
                            resolvedPrice: $resolvedPrice,
                            priceResolutionStatus: $priceStatus,
                        );
                    })
                    ->values()
                    ->all();

                return new ProductExecutionAggregate(
                    product: $product,
                    productValues: $productValues,
                    variants: $variants,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ProductFieldValue|VariantFieldValue>  $storedValues
     */
    private function resolveBindingValue(
        FieldBinding $binding,
        Product $product,
        ?ProductVariant $variant,
        Collection $storedValues,
    ): mixed {
        return match ($binding->storage_type) {
            AttributeStorageType::Column => $this->resolveColumnValue($binding, $product, $variant),
            AttributeStorageType::Dynamic => $this->resolveDynamicValue($binding, $storedValues),
            AttributeStorageType::Relation => null,
        };
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

        if ($table === 'product_variants' && $variant !== null) {
            return $variant->getAttribute($column);
        }

        return null;
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
