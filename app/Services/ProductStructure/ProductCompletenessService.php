<?php

namespace App\Services\ProductStructure;

use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductActiveOptionalGroup;
use App\Models\ProductFieldValue;
use App\Models\ProductType;
use App\Models\ProductTypeGroupPlacement;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use App\Services\Catalog\GovernedProductVariantColumnEligibility;
use App\Services\Catalog\GovernedProductVariantColumnValuePolicy;
use App\Services\Fields\GovernedDynamicFieldValueWriter;
use App\Support\ProductStructure\ProductCompletenessGroupProjection;
use App\Support\ProductStructure\ProductCompletenessProjection;

final class ProductCompletenessService
{
    public function __construct(
        private readonly GovernedDynamicFieldValueWriter $dynamicValueWriter,
        private readonly GovernedProductVariantColumnEligibility $columnEligibility,
        private readonly GovernedProductVariantColumnValuePolicy $columnValuePolicy,
    ) {}

    public function project(Product $product, ?string $locale = null, ?ProductType $typeOverride = null): ProductCompletenessProjection
    {
        $locale ??= app()->getLocale();
        $product = Product::withoutWorkspaceScope()
            ->whereKey($product->id)
            ->where('workspace_id', $product->workspace_id)
            ->firstOrFail();
        $type = $typeOverride instanceof ProductType
            ? ProductType::withoutWorkspaceScope()
                ->where('workspace_id', $product->workspace_id)
                ->whereKey($typeOverride->id)
                ->firstOrFail()
            : ProductType::withoutWorkspaceScope()
                ->where('workspace_id', $product->workspace_id)
                ->whereKey($product->product_type_id)
                ->firstOrFail();

        $groups = ProductTypeGroupPlacement::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_type_id', $type->id)
            ->with([
                'attributeGroup' => fn ($query) => $query->withoutGlobalScopes(),
                'fieldPlacements' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order')->orderBy('id'),
                'fieldPlacements.fieldBinding' => fn ($query) => $query->withoutGlobalScopes(),
                'fieldPlacements.fieldBinding.fieldDefinition' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $overrideStates = ProductActiveOptionalGroup::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->whereIn('product_type_group_placement_id', $groups->pluck('id'))
            ->pluck('is_active', 'product_type_group_placement_id');
        $activeVariants = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $overrideStateMap = $overrideStates->all();
        $requiredPlacements = $groups
            ->filter(fn (ProductTypeGroupPlacement $group): bool => $group->attributeGroup?->status === 'active'
                && $this->groupIsActive($group, $overrideStateMap))
            ->flatMap(fn (ProductTypeGroupPlacement $group) => $group->fieldPlacements)
            ->filter(fn ($placement): bool => (bool) $placement->required_for_completeness)
            ->filter(function ($placement) use ($product): bool {
                $binding = $placement->fieldBinding;
                $definition = $binding?->fieldDefinition;

                return $binding instanceof FieldBinding
                    && $definition instanceof FieldDefinition
                    && $this->bindingDefinitionIsEligible((string) $product->workspace_id, $binding, $definition);
            });

        $dynamicProductBindingIds = $requiredPlacements
            ->filter(fn ($placement): bool => $placement->fieldBinding->object_type === FieldObjectType::Product
                && $placement->fieldBinding->storage_type === AttributeStorageType::Dynamic)
            ->pluck('field_binding_id')->unique()->values();
        $dynamicVariantBindingIds = $requiredPlacements
            ->filter(fn ($placement): bool => $placement->fieldBinding->object_type === FieldObjectType::ProductVariant
                && $placement->fieldBinding->storage_type === AttributeStorageType::Dynamic)
            ->pluck('field_binding_id')->unique()->values();
        $productSlots = ProductFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->whereIn('field_binding_id', $dynamicProductBindingIds)
            ->get()
            ->keyBy('field_binding_id');

        $variantSlots = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->whereIn('variant_id', $activeVariants->pluck('id'))
            ->whereIn('field_binding_id', $dynamicVariantBindingIds)
            ->get()
            ->keyBy(fn (VariantFieldValue $slot): string => $slot->variant_id.':'.$slot->field_binding_id);

        $groupProjections = [];
        $missingProductBindingIds = [];
        $missingVariantCells = [];
        $activeOptionalGroupPlacementIds = [];
        $inactiveOptionalGroupPlacementIds = [];
        $totalRequired = 0;
        $totalFilled = 0;

        foreach ($groups as $group) {
            if ($group->attributeGroup?->status !== 'active') {
                continue;
            }

            $isActive = $this->groupIsActive($group, $overrideStates->all());
            if ($group->is_optional) {
                if ($isActive) {
                    $activeOptionalGroupPlacementIds[] = (string) $group->id;
                } else {
                    $inactiveOptionalGroupPlacementIds[] = (string) $group->id;
                }
            }

            $groupRequired = 0;
            $groupFilled = 0;
            $groupMissingProduct = [];
            $groupMissingVariants = [];

            if ($isActive) {
                foreach ($group->fieldPlacements as $placement) {
                    if (! $placement->required_for_completeness) {
                        continue;
                    }

                    $binding = $placement->fieldBinding;
                    $definition = $binding?->fieldDefinition;
                    if (! $binding instanceof FieldBinding
                        || ! $definition instanceof FieldDefinition
                        || ! $this->bindingDefinitionIsEligible((string) $product->workspace_id, $binding, $definition)) {
                        continue;
                    }

                    if ($binding->object_type === FieldObjectType::Product) {
                        $groupRequired++;
                        if ($this->productBindingIsComplete($product, $binding, $definition, $productSlots->get($binding->id), $locale)) {
                            $groupFilled++;
                        } else {
                            $groupMissingProduct[] = (string) $binding->id;
                        }
                    }
                    if ($binding->object_type === FieldObjectType::ProductVariant && $activeVariants->isNotEmpty()) {
                        $groupRequired++;
                        $variantRequirementComplete = true;

                        foreach ($activeVariants as $variant) {
                            $slot = $variantSlots->get($variant->id.':'.$binding->id);
                            if ($this->variantBindingIsComplete($variant, $binding, $definition, $slot, $locale)) {
                                continue;
                            }

                            $variantRequirementComplete = false;
                            $groupMissingVariants[] = [
                                'variant_id' => (int) $variant->id,
                                'field_binding_id' => (string) $binding->id,
                            ];
                        }

                        if ($variantRequirementComplete) {
                            $groupFilled++;
                        }
                    }
                }
            }

            $totalRequired += $groupRequired;
            $totalFilled += $groupFilled;
            array_push($missingProductBindingIds, ...$groupMissingProduct);
            array_push($missingVariantCells, ...$groupMissingVariants);

            $groupProjections[] = new ProductCompletenessGroupProjection(
                groupPlacementId: (string) $group->id,
                attributeGroupId: (string) $group->attribute_group_id,
                isOptional: (bool) $group->is_optional,
                isActive: $isActive,
                requiredCount: $groupRequired,
                filledCount: $groupFilled,
                percentage: $this->percentage($groupFilled, $groupRequired),
                missingProductBindingIds: $groupMissingProduct,
                missingVariantCells: $groupMissingVariants,
            );
        }

        return new ProductCompletenessProjection(
            workspaceId: (string) $product->workspace_id,
            productId: (int) $product->id,
            productTypeId: (string) $type->id,
            structureRevision: (int) $type->structure_revision,
            locale: $locale,
            requiredCount: $totalRequired,
            filledCount: $totalFilled,
            percentage: $this->percentage($totalFilled, $totalRequired),
            groups: $groupProjections,
            missingProductBindingIds: array_values(array_unique($missingProductBindingIds)),
            missingVariantCells: $missingVariantCells,
            activeOptionalGroupPlacementIds: $activeOptionalGroupPlacementIds,
            inactiveOptionalGroupPlacementIds: $inactiveOptionalGroupPlacementIds,
        );
    }

    private function bindingDefinitionIsEligible(
        string $workspaceId,
        FieldBinding $binding,
        FieldDefinition $definition,
    ): bool {
        if ($binding->workspace_id !== null && (string) $binding->workspace_id !== $workspaceId) {
            return false;
        }

        if (($definition->workspace_id ?? null) !== ($binding->workspace_id ?? null)) {
            return false;
        }

        return $binding->status === AttributeStatus::Active
            && $definition->status === AttributeStatus::Active;
    }

    /** @param array<string, mixed> $overrides */
    private function groupIsActive(ProductTypeGroupPlacement $group, array $overrides): bool
    {
        if (! $group->is_optional) {
            return true;
        }

        if (array_key_exists((string) $group->id, $overrides)) {
            return (bool) $overrides[(string) $group->id];
        }

        return (bool) $group->default_active;
    }

    private function productBindingIsComplete(
        Product $product,
        FieldBinding $binding,
        FieldDefinition $definition,
        mixed $slot,
        string $locale,
    ): bool {
        return $this->bindingIsComplete($product, $binding, $definition, $slot, $locale);
    }

    private function variantBindingIsComplete(
        ProductVariant $variant,
        FieldBinding $binding,
        FieldDefinition $definition,
        mixed $slot,
        string $locale,
    ): bool {
        return $this->bindingIsComplete($variant, $binding, $definition, $slot, $locale);
    }

    private function bindingIsComplete(
        Product|ProductVariant $target,
        FieldBinding $binding,
        FieldDefinition $definition,
        mixed $slot,
        string $locale,
    ): bool {
        if ($binding->storage_type === AttributeStorageType::Dynamic) {
            return $this->dynamicValueWriter->storedSlotIsValid(
                $definition,
                $slot,
                $definition->is_localizable ? $locale : null,
            );
        }

        if ($binding->storage_type !== AttributeStorageType::Column) {
            return false;
        }
        $rule = $this->columnEligibility->matchingRule($binding, $definition);
        if ($rule === null) {
            return false;
        }

        $value = $target->getAttribute($rule['column']);
        if ($value === null) {
            return false;
        }

        try {
            return $this->columnValuePolicy->normalizeSetValue($definition->code, $value) === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    private function percentage(int $filled, int $required): int
    {
        if ($required === 0) {
            return 100;
        }

        return (int) round(($filled / $required) * 100);
    }
}
