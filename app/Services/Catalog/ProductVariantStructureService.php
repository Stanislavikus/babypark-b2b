<?php

namespace App\Services\Catalog;

use App\Enums\AttributeDataType;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\Product;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductVariant;
use App\Models\ProductVariantAxis;
use App\Models\User;
use App\Models\VariantFieldValue;
use App\Models\Workspace;
use App\Services\Fields\GovernedDynamicFieldValueWriter;
use App\Services\ProductStructure\ProductOptionalGroupStateResolver;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Catalog\Exceptions\ProductVariantStructureException;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProductVariantStructureService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly GovernedDynamicFieldValueWriter $writer,
        private readonly ProductOptionalGroupStateResolver $optionalGroupStateResolver,
    ) {}

    /**
     * @return Collection<string, array{binding:FieldBinding,label:string,options:array<string,string>}>
     */
    public function axisCandidates(Product $product): Collection
    {
        $product = Product::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->whereKey($product->id)
            ->firstOrFail();

        return ProductTypeFieldPlacement::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_type_id', $product->product_type_id)
            ->with([
                'groupPlacement' => fn ($query) => $query->withoutGlobalScopes(),
                'groupPlacement.attributeGroup' => fn ($query) => $query->withoutGlobalScopes(),
                'fieldBinding' => fn ($query) => $query->withoutGlobalScopes(),
                'fieldBinding.fieldDefinition' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->orderBy('sort_order')
            ->get()
            ->filter(function (ProductTypeFieldPlacement $placement) use ($product): bool {
                $binding = $placement->fieldBinding;
                $definition = $binding?->fieldDefinition;
                $groupPlacement = $placement->groupPlacement;

                if (! $binding instanceof FieldBinding || $definition === null || $groupPlacement === null) {
                    return false;
                }

                if ($groupPlacement->attributeGroup?->status !== 'active') {
                    return false;
                }

                if (! $this->optionalGroupStateResolver->isActive($product, $groupPlacement)) {
                    return false;
                }

                return $this->bindingEligibleForAxis($product, $binding);
            })
            ->mapWithKeys(function (ProductTypeFieldPlacement $placement): array {
                $binding = $placement->fieldBinding;
                $definition = $binding->fieldDefinition;
                $locale = app()->getLocale();

                return [
                    (string) $binding->id => [
                        'binding' => $binding,
                        'label' => $definition->localized_labels[$locale]
                            ?? $definition->localized_labels['uk']
                            ?? $definition->localized_labels['en']
                            ?? $definition->code,
                        'options' => $this->optionLabels($binding),
                    ],
                ];
            });
    }

    /**
     * @return Collection<int, ProductVariantAxis>
     */
    public function declaredAxes(Product $product): Collection
    {
        return ProductVariantAxis::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->with(['fieldBinding' => fn ($query) => $query->withoutGlobalScopes(), 'fieldBinding.fieldDefinition' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<string>  $additionalOptionCodes
     * @return Collection<int, ProductVariant>
     */
    public function promoteSimple(
        User $actor,
        Workspace $workspace,
        Product $product,
        string $fieldBindingId,
        string $existingVariantOptionCode,
        array $additionalOptionCodes,
    ): Collection {
        return DB::transaction(function () use ($actor, $workspace, $product, $fieldBindingId, $existingVariantOptionCode, $additionalOptionCodes): Collection {
            [$lockedWorkspace, $lockedProduct] = $this->lockContext($actor, $workspace, $product);
            $this->assertSourceNeutral($lockedProduct);

            $axes = ProductVariantAxis::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('product_id', $lockedProduct->id)
                ->lockForUpdate()
                ->get();
            if ($axes->isNotEmpty()) {
                throw ProductVariantStructureException::axesAlreadyDeclared();
            }

            $variants = ProductVariant::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('product_id', $lockedProduct->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($variants->count() !== 1) {
                throw ProductVariantStructureException::notSimple();
            }

            $candidate = $this->axisCandidate($lockedProduct, $fieldBindingId);
            $codes = array_values(array_unique(array_map('strval', $additionalOptionCodes)));
            $codes = array_values(array_filter($codes, fn (string $code): bool => $code !== $existingVariantOptionCode));
            if ($codes === []) {
                throw ProductVariantStructureException::additionalValuesRequired();
            }
            $this->assertOptionAllowed($candidate['options'], $existingVariantOptionCode);
            foreach ($codes as $code) {
                $this->assertOptionAllowed($candidate['options'], $code);
            }

            ProductVariantAxis::withoutWorkspaceScope()->create([
                'workspace_id' => $lockedWorkspace->id,
                'product_id' => $lockedProduct->id,
                'field_binding_id' => $fieldBindingId,
                'sort_order' => 0,
            ]);

            /** @var ProductVariant $existing */
            $existing = $variants->first();
            $this->writer->setIfCurrentValue(
                workspaceId: (string) $lockedWorkspace->id,
                targetType: FieldObjectType::ProductVariant,
                targetId: $existing->id,
                fieldBindingId: $fieldBindingId,
                expectedCurrentValue: $this->currentSelectValue($lockedWorkspace->id, $existing->id, $fieldBindingId),
                value: $existingVariantOptionCode,
            );

            foreach ($codes as $code) {
                $variant = ProductVariant::withoutWorkspaceScope()->create([
                    'workspace_id' => $lockedWorkspace->id,
                    'product_id' => $lockedProduct->id,
                    'onec_guid' => null,
                    'sku' => null,
                    'barcode_ean' => null,
                    'attributes' => [],
                    'is_active' => true,
                ]);
                $this->writer->setIfCurrentValue(
                    workspaceId: (string) $lockedWorkspace->id,
                    targetType: FieldObjectType::ProductVariant,
                    targetId: $variant->id,
                    fieldBindingId: $fieldBindingId,
                    expectedCurrentValue: null,
                    value: $code,
                );
            }

            return ProductVariant::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('product_id', $lockedProduct->id)
                ->orderBy('id')
                ->get();
        });
    }

    /**
     * @param  array<int|string, string>  $variantOptionCodes
     */
    public function addAxis(
        User $actor,
        Workspace $workspace,
        Product $product,
        string $fieldBindingId,
        array $variantOptionCodes,
    ): void {
        DB::transaction(function () use ($actor, $workspace, $product, $fieldBindingId, $variantOptionCodes): void {
            [$lockedWorkspace, $lockedProduct] = $this->lockContext($actor, $workspace, $product);
            $this->assertSourceNeutral($lockedProduct);

            $axes = ProductVariantAxis::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('product_id', $lockedProduct->id)
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->get();
            if ($axes->isEmpty()) {
                throw ProductVariantStructureException::axesRequired();
            }
            if ($axes->contains(fn (ProductVariantAxis $axis): bool => (string) $axis->field_binding_id === $fieldBindingId)) {
                throw ProductVariantStructureException::axesAlreadyDeclared();
            }

            $variants = ProductVariant::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('product_id', $lockedProduct->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($variants->where('is_active', true)->count() !== $variants->count()) {
                throw ProductVariantStructureException::inactiveVariantsUnsupported();
            }

            $candidate = $this->axisCandidate($lockedProduct, $fieldBindingId);
            $normalized = collect($variantOptionCodes)->mapWithKeys(fn ($value, $key): array => [(string) $key => (string) $value])->all();
            $variantIds = $variants->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $assignmentIds = array_map('intval', array_keys($normalized));
            sort($assignmentIds);
            if ($variantIds !== $assignmentIds) {
                throw ProductVariantStructureException::incompleteAssignments();
            }
            foreach ($normalized as $code) {
                $this->assertOptionAllowed($candidate['options'], $code);
            }

            $this->assertExistingCombinationsUniqueAndComplete($lockedProduct, $variants, $axes);

            ProductVariantAxis::withoutWorkspaceScope()->create([
                'workspace_id' => $lockedWorkspace->id,
                'product_id' => $lockedProduct->id,
                'field_binding_id' => $fieldBindingId,
                'sort_order' => ((int) $axes->max('sort_order')) + 100,
            ]);

            foreach ($variants as $variant) {
                $expected = $this->currentSelectValue($lockedWorkspace->id, $variant->id, $fieldBindingId);
                $this->writer->setIfCurrentValue(
                    workspaceId: (string) $lockedWorkspace->id,
                    targetType: FieldObjectType::ProductVariant,
                    targetId: $variant->id,
                    fieldBindingId: $fieldBindingId,
                    expectedCurrentValue: $expected,
                    value: $normalized[(string) $variant->id],
                );
            }
        });
    }

    /**
     * @param  array<string, string>  $axisValues
     */
    public function addVariant(
        User $actor,
        Workspace $workspace,
        Product $product,
        array $axisValues,
        ?string $sku = null,
        ?string $gtin = null,
    ): ProductVariant {
        return DB::transaction(function () use ($actor, $workspace, $product, $axisValues, $sku, $gtin): ProductVariant {
            [$lockedWorkspace, $lockedProduct] = $this->lockContext($actor, $workspace, $product);
            $this->assertSourceNeutral($lockedProduct);

            $axes = ProductVariantAxis::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('product_id', $lockedProduct->id)
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->get();
            if ($axes->isEmpty()) {
                throw ProductVariantStructureException::axesRequired();
            }

            $variants = ProductVariant::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('product_id', $lockedProduct->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($variants->where('is_active', true)->count() !== $variants->count()) {
                throw ProductVariantStructureException::inactiveVariantsUnsupported();
            }
            $this->assertExistingCombinationsUniqueAndComplete($lockedProduct, $variants, $axes);

            $normalized = collect($axisValues)->mapWithKeys(fn ($value, $key): array => [(string) $key => (string) $value])->all();
            $axisBindingIds = $axes->pluck('field_binding_id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
            $providedBindingIds = array_keys($normalized);
            sort($providedBindingIds);
            if ($axisBindingIds !== $providedBindingIds) {
                throw ProductVariantStructureException::incompleteAssignments();
            }

            foreach ($axes as $axis) {
                $candidate = $this->axisCandidate($lockedProduct, (string) $axis->field_binding_id);
                $this->assertOptionAllowed($candidate['options'], $normalized[(string) $axis->field_binding_id]);
            }

            $newKey = $this->combinationKey($axes, $normalized);
            $existingKeys = $this->existingCombinationKeys($lockedWorkspace->id, $variants, $axes);
            if (in_array($newKey, $existingKeys, true)) {
                throw ProductVariantStructureException::duplicateCombination();
            }

            $sku = filled($sku) ? trim((string) $sku) : null;
            $gtin = filled($gtin) ? trim((string) $gtin) : null;
            if ($sku !== null && ProductVariant::withoutWorkspaceScope()->where('workspace_id', $lockedWorkspace->id)->where('sku', $sku)->exists()) {
                throw ProductVariantStructureException::duplicateSku();
            }

            $variant = ProductVariant::withoutWorkspaceScope()->create([
                'workspace_id' => $lockedWorkspace->id,
                'product_id' => $lockedProduct->id,
                'onec_guid' => null,
                'sku' => $sku,
                'barcode_ean' => $gtin,
                'attributes' => [],
                'is_active' => true,
            ]);

            foreach ($axes as $axis) {
                $bindingId = (string) $axis->field_binding_id;
                $this->writer->setIfCurrentValue(
                    workspaceId: (string) $lockedWorkspace->id,
                    targetType: FieldObjectType::ProductVariant,
                    targetId: $variant->id,
                    fieldBindingId: $bindingId,
                    expectedCurrentValue: null,
                    value: $normalized[$bindingId],
                );
            }

            return $variant->fresh();
        });
    }

    /**
     * @return array{binding:FieldBinding,label:string,options:array<string,string>}
     */
    private function axisCandidate(Product $product, string $bindingId): array
    {
        $candidate = $this->axisCandidates($product)->get($bindingId);
        if (! is_array($candidate)) {
            throw ProductVariantStructureException::invalidAxis();
        }

        return $candidate;
    }

    private function bindingEligibleForAxis(Product $product, FieldBinding $binding): bool
    {
        $definition = $binding->fieldDefinition;

        return $definition !== null
            && ($binding->workspace_id === null || (string) $binding->workspace_id === (string) $product->workspace_id)
            && $binding->status === AttributeStatus::Active
            && $binding->object_type === FieldObjectType::ProductVariant
            && $binding->storage_type === AttributeStorageType::Dynamic
            && (bool) data_get($binding->visibility_settings, 'admin', false)
            && $definition->status === AttributeStatus::Active
            && $definition->data_type === AttributeDataType::Select
            && ! $definition->is_multi_value
            && ! $definition->is_localizable
            && $this->optionLabels($binding) !== [];
    }

    /** @return array<string,string> */
    private function optionLabels(FieldBinding $binding): array
    {
        $definition = $binding->fieldDefinition;
        $locale = app()->getLocale();
        $options = $definition?->validation_rules['options'] ?? [];

        if (! is_array($options)) {
            return [];
        }

        return collect($options)
            ->filter(fn ($option): bool => is_array($option) && is_string($option['code'] ?? null) && $option['code'] !== '')
            ->mapWithKeys(function (array $option) use ($locale): array {
                $code = (string) $option['code'];
                $labels = is_array($option['labels'] ?? null) ? $option['labels'] : [];

                return [$code => (string) ($labels[$locale] ?? $labels['uk'] ?? $labels['en'] ?? $code)];
            })
            ->all();
    }

    private function assertOptionAllowed(array $options, string $code): void
    {
        if (! array_key_exists($code, $options)) {
            throw ProductVariantStructureException::invalidOption();
        }
    }

    /** @return array{0:Workspace,1:Product} */
    private function lockContext(User $actor, Workspace $workspace, Product $product): array
    {
        $lockedWorkspace = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
        if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_PRODUCTS)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $lockedProduct = Product::withoutWorkspaceScope()
            ->where('workspace_id', $lockedWorkspace->id)
            ->whereKey($product->id)
            ->lockForUpdate()
            ->firstOrFail();

        return [$lockedWorkspace, $lockedProduct];
    }

    private function assertSourceNeutral(Product $product): void
    {
        if (filled($product->onec_guid)
            || ProductVariant::withoutWorkspaceScope()
                ->where('workspace_id', $product->workspace_id)
                ->where('product_id', $product->id)
                ->whereNotNull('onec_guid')
                ->exists()) {
            throw ProductVariantStructureException::sourceOwned();
        }
    }

    private function currentSelectValue(string $workspaceId, int $variantId, string $bindingId): ?string
    {
        return VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('variant_id', $variantId)
            ->where('field_binding_id', $bindingId)
            ->value('value_text');
    }

    private function assertExistingCombinationsUniqueAndComplete(Product $product, Collection $variants, Collection $axes): void
    {
        $keys = $this->existingCombinationKeys((string) $product->workspace_id, $variants, $axes);
        if (count($keys) !== count(array_unique($keys))) {
            throw ProductVariantStructureException::duplicateCombination();
        }
    }

    /** @return list<string> */
    private function existingCombinationKeys(string $workspaceId, Collection $variants, Collection $axes): array
    {
        $rows = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('variant_id', $variants->pluck('id'))
            ->whereIn('field_binding_id', $axes->pluck('field_binding_id'))
            ->get()
            ->keyBy(fn (VariantFieldValue $row): string => $row->variant_id.':'.$row->field_binding_id);

        $keys = [];
        foreach ($variants as $variant) {
            $values = [];
            foreach ($axes as $axis) {
                $row = $rows->get($variant->id.':'.$axis->field_binding_id);
                $value = $row?->value_text;
                if (! is_string($value) || $value === '') {
                    throw ProductVariantStructureException::incompleteAssignments();
                }
                $values[(string) $axis->field_binding_id] = $value;
            }
            $keys[] = $this->combinationKey($axes, $values);
        }

        return $keys;
    }

    private function combinationKey(Collection $axes, array $values): string
    {
        return $axes
            ->sortBy('sort_order')
            ->map(fn (ProductVariantAxis $axis): string => $axis->field_binding_id.'='.($values[(string) $axis->field_binding_id] ?? ''))
            ->implode('|');
    }
}
