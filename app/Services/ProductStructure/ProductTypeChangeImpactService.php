<?php

namespace App\Services\ProductStructure;

use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\Product;
use App\Models\ProductActiveOptionalGroup;
use App\Models\ProductFieldValue;
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use App\Support\ProductStructure\Exceptions\ProductStructureInvariantException;
use App\Support\ProductStructure\ProductTypeChangeImpact;
use Illuminate\Support\Collection;

final class ProductTypeChangeImpactService
{
    public function preview(Product $product, ProductType $targetType): ProductTypeChangeImpact
    {
        if ((string) $product->workspace_id !== (string) $targetType->workspace_id) {
            throw new ProductStructureInvariantException('Product and target ProductType must belong to the same workspace.');
        }

        $currentType = ProductType::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->whereKey($product->product_type_id)
            ->firstOrFail();

        $currentFields = $this->fieldPlacements($currentType);
        $targetFields = $this->fieldPlacements($targetType);
        $currentBindingIds = $currentFields->pluck('field_binding_id')->map('strval')->all();
        $targetBindingIds = $targetFields->pluck('field_binding_id')->map('strval')->all();
        $removedBindingIds = $this->sortedDiff($currentBindingIds, $targetBindingIds);
        $addedBindingIds = $this->sortedDiff($targetBindingIds, $currentBindingIds);

        $currentGroups = $this->groupPlacements($currentType)->pluck('attribute_group_id')->map('strval')->all();
        $targetGroups = $this->groupPlacements($targetType)->pluck('attribute_group_id')->map('strval')->all();

        $currentRequired = $currentFields->where('required_for_completeness', true)
            ->pluck('field_binding_id')->map('strval')->all();
        $targetRequired = $targetFields->where('required_for_completeness', true)
            ->pluck('field_binding_id')->map('strval')->all();

        [$outOfTypeProductBindingIds, $outOfTypeVariantCells] = $this->storedOutOfTypeValues($product, $removedBindingIds);

        $targetPlacementIds = $this->groupPlacements($targetType)->pluck('id')->map('strval')->all();
        $invalidOverrideQuery = ProductActiveOptionalGroup::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id);
        if ($targetPlacementIds !== []) {
            $invalidOverrideQuery->whereNotIn('product_type_group_placement_id', $targetPlacementIds);
        }

        $activeVariantIds = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return new ProductTypeChangeImpact(
            workspaceId: (string) $product->workspace_id,
            productId: (int) $product->id,
            fromProductTypeId: (string) $currentType->id,
            fromStructureRevision: (int) $currentType->structure_revision,
            toProductTypeId: (string) $targetType->id,
            toStructureRevision: (int) $targetType->structure_revision,
            addedBindingIds: $addedBindingIds,
            removedBindingIds: $removedBindingIds,
            addedAttributeGroupIds: $this->sortedDiff($targetGroups, $currentGroups),
            removedAttributeGroupIds: $this->sortedDiff($currentGroups, $targetGroups),
            outOfTypeProductBindingIds: $outOfTypeProductBindingIds,
            outOfTypeVariantCells: $outOfTypeVariantCells,
            invalidOptionalOverrideIds: $invalidOverrideQuery->orderBy('id')->pluck('id')->map('strval')->all(),
            newlyRequiredBindingIds: $this->sortedDiff($targetRequired, $currentRequired),
            affectedActiveVariantIds: $activeVariantIds,
        );
    }

    private function fieldPlacements(ProductType $type): Collection
    {
        return ProductTypeFieldPlacement::withoutWorkspaceScope()
            ->where('workspace_id', $type->workspace_id)
            ->where('product_type_id', $type->id)
            ->orderBy('field_binding_id')
            ->get();
    }

    private function groupPlacements(ProductType $type): Collection
    {
        return ProductTypeGroupPlacement::withoutWorkspaceScope()
            ->where('workspace_id', $type->workspace_id)
            ->where('product_type_id', $type->id)
            ->orderBy('attribute_group_id')
            ->get();
    }

    private function storedOutOfTypeValues(Product $product, array $removedBindingIds): array
    {
        if ($removedBindingIds === []) {
            return [[], []];
        }

        $bindings = FieldBinding::withoutWorkspaceScope()
            ->whereIn('id', $removedBindingIds)
            ->orderBy('id')
            ->get();
        $variants = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->get();
        $productDynamic = ProductFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->whereIn('field_binding_id', $removedBindingIds)
            ->pluck('field_binding_id')
            ->map('strval')
            ->flip();
        $variantIds = $variants->pluck('id')->all();
        $variantDynamic = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->whereIn('variant_id', $variantIds)
            ->whereIn('field_binding_id', $removedBindingIds)
            ->get(['variant_id', 'field_binding_id'])
            ->mapWithKeys(fn (VariantFieldValue $row): array => [((int) $row->variant_id).':'.$row->field_binding_id => true]);

        $productHits = [];
        $variantHits = [];

        foreach ($bindings as $binding) {
            if ($binding->object_type === FieldObjectType::Product) {
                $present = $binding->storage_type === AttributeStorageType::Dynamic
                    ? $productDynamic->has((string) $binding->id)
                    : $this->columnValuePresent($binding, $product);
                if ($present) {
                    $productHits[] = (string) $binding->id;
                }

                continue;
            }

            if ($binding->object_type !== FieldObjectType::ProductVariant) {
                continue;
            }

            foreach ($variants as $variant) {
                $present = $binding->storage_type === AttributeStorageType::Dynamic
                    ? $variantDynamic->has(((int) $variant->id).':'.$binding->id)
                    : $this->columnValuePresent($binding, $variant);
                if ($present) {
                    $variantHits[] = ['variant_id' => (int) $variant->id, 'field_binding_id' => (string) $binding->id];
                }
            }
        }

        sort($productHits);
        usort($variantHits, fn (array $a, array $b): int => [$a['variant_id'], $a['field_binding_id']] <=> [$b['variant_id'], $b['field_binding_id']]);

        return [$productHits, $variantHits];
    }

    private function columnValuePresent(FieldBinding $binding, object $record): bool
    {
        if (! is_string($binding->storage_path) || $binding->storage_path === '') {
            return false;
        }

        [, $column] = array_pad(explode('.', $binding->storage_path, 2), 2, null);
        if (! is_string($column) || $column === '') {
            return false;
        }

        $value = $record->getAttribute($column);

        return $value !== null && $value !== '' && $value !== [];
    }

    private function sortedDiff(array $left, array $right): array
    {
        $values = array_values(array_diff(array_unique($left), array_unique($right)));
        sort($values);

        return $values;
    }
}
