<?php

namespace App\Services\ProductStructure;

use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Models\AttributeGroup;
use App\Models\FieldBinding;
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class BasicProductStructureReconciler
{
    public function available(): bool
    {
        return Schema::hasTable('product_types')
            && Schema::hasTable('attribute_groups')
            && Schema::hasTable('product_type_group_placements')
            && Schema::hasTable('product_type_field_placements');
    }

    public function ensureWorkspace(string $workspaceId): ProductType
    {
        if (! $this->available()) {
            throw new \LogicException('Product Structure foundation is not available.');
        }

        $this->reconcileWorkspace($workspaceId);

        return ProductType::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('is_default', true)
            ->sole();
    }

    public function reconcileBinding(FieldBinding $binding): void
    {
        if (! $this->available()) {
            return;
        }

        if ($binding->workspace_id !== null) {
            $this->reconcileWorkspace((string) $binding->workspace_id);

            return;
        }

        foreach (Workspace::query()->orderBy('id')->pluck('id') as $workspaceId) {
            $this->reconcileWorkspace((string) $workspaceId);
        }
    }

    public function reconcileWorkspace(string $workspaceId): void
    {
        if (! $this->available()) {
            return;
        }

        DB::transaction(function () use ($workspaceId): void {
            Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();

            $productTypeId = ProductStructureIdentity::basicProductTypeId($workspaceId);
            $type = ProductType::withoutWorkspaceScope()->find($productTypeId);
            $typeWasNew = ! $type instanceof ProductType;
            if ($typeWasNew) {
                $type = new ProductType;
                $type->setAttribute('id', $productTypeId);
            }
            $type->fill([
                'workspace_id' => $workspaceId,
                'code' => 'basic_product',
                'localized_labels' => ['en' => 'Basic Product', 'uk' => 'Базовий товар'],
                'description' => null,
                'status' => 'active',
                'is_default' => true,
            ]);
            if ($typeWasNew) {
                $type->structure_revision = 1;
                $type->save();
            } elseif ($type->isDirty()) {
                $type->save();
            }

            $changed = false;
            $bindings = FieldBinding::withoutWorkspaceScope()
                ->where('status', AttributeStatus::Active)
                ->whereIn('object_type', [FieldObjectType::Product, FieldObjectType::ProductVariant])
                ->where(function ($query) use ($workspaceId): void {
                    $query->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->filter(fn (FieldBinding $binding): bool => (bool) ($binding->visibility_settings['admin'] ?? false))
                ->values();

            $groupCodes = $bindings->pluck('field_group')->filter()->unique()->values();
            $groupOrder = $bindings->groupBy('field_group')
                ->map(fn ($rows): int => (int) $rows->min('sort_order'))
                ->sort()
                ->keys()
                ->values();

            $groupPlacementIds = [];

            foreach ($groupOrder as $groupIndex => $groupCode) {
                $groupCode = (string) $groupCode;
                $groupId = ProductStructureIdentity::attributeGroupId($workspaceId, $groupCode);
                $group = AttributeGroup::withoutWorkspaceScope()->find($groupId);
                $groupWasNew = ! $group instanceof AttributeGroup;
                if ($groupWasNew) {
                    $group = new AttributeGroup;
                    $group->setAttribute('id', $groupId);
                }
                $group->fill([
                    'workspace_id' => $workspaceId,
                    'code' => $groupCode,
                    'status' => 'active',
                ]);
                if ($groupWasNew) {
                    $group->localized_labels = [
                        'uk' => (string) config("attribute_dictionary.groups.{$groupCode}", Str::headline($groupCode)),
                        'en' => Str::headline($groupCode),
                    ];
                }
                if (! $group->exists || $group->isDirty()) {
                    $group->save();
                    $changed = true;
                }

                $groupPlacementId = ProductStructureIdentity::groupPlacementId($workspaceId, $productTypeId, $groupId);
                $groupPlacementIds[] = $groupPlacementId;
                $placement = ProductTypeGroupPlacement::withoutWorkspaceScope()->find($groupPlacementId);
                if (! $placement instanceof ProductTypeGroupPlacement) {
                    $placement = new ProductTypeGroupPlacement;
                    $placement->setAttribute('id', $groupPlacementId);
                }
                $placement->fill([
                    'workspace_id' => $workspaceId,
                    'product_type_id' => $productTypeId,
                    'attribute_group_id' => $groupId,
                    'sort_order' => $groupIndex * 100,
                    'is_optional' => false,
                    'default_active' => true,
                ]);
                if (! $placement->exists || $placement->isDirty()) {
                    $placement->save();
                    $changed = true;
                }

                foreach ($bindings->where('field_group', $groupCode) as $binding) {
                    $fieldPlacementId = ProductStructureIdentity::fieldPlacementId($workspaceId, $productTypeId, (string) $binding->id);
                    $fieldPlacement = ProductTypeFieldPlacement::withoutWorkspaceScope()->find($fieldPlacementId);
                    if (! $fieldPlacement instanceof ProductTypeFieldPlacement) {
                        $fieldPlacement = new ProductTypeFieldPlacement;
                        $fieldPlacement->setAttribute('id', $fieldPlacementId);
                    }
                    $fieldPlacement->fill([
                        'workspace_id' => $workspaceId,
                        'product_type_id' => $productTypeId,
                        'product_type_group_placement_id' => $groupPlacementId,
                        'field_binding_id' => $binding->id,
                        'sort_order' => (int) $binding->sort_order,
                        'required_for_completeness' => (bool) $binding->is_required,
                    ]);
                    if (! $fieldPlacement->exists || $fieldPlacement->isDirty()) {
                        $fieldPlacement->save();
                        $changed = true;
                    }
                }
            }

            $eligibleBindingIds = $bindings->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $staleFieldQuery = ProductTypeFieldPlacement::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->where('product_type_id', $productTypeId);
            if ($eligibleBindingIds !== []) {
                $staleFieldQuery->whereNotIn('field_binding_id', $eligibleBindingIds);
            }
            if ($eligibleBindingIds === [] || $staleFieldQuery->exists()) {
                $deleted = $staleFieldQuery->delete();
                $changed = $changed || $deleted > 0;
            }

            $staleGroupQuery = ProductTypeGroupPlacement::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->where('product_type_id', $productTypeId);
            if ($groupPlacementIds !== []) {
                $staleGroupQuery->whereNotIn('id', $groupPlacementIds);
            }
            if ($groupPlacementIds === [] || $staleGroupQuery->exists()) {
                $deleted = $staleGroupQuery->delete();
                $changed = $changed || $deleted > 0;
            }

            if ($changed && ! $typeWasNew) {
                ProductType::withoutWorkspaceScope()->whereKey($productTypeId)->increment('structure_revision');
            }

            if (Schema::hasColumn('products', 'product_type_id')) {
                DB::table('products')
                    ->where('workspace_id', $workspaceId)
                    ->whereNull('product_type_id')
                    ->update(['product_type_id' => $productTypeId]);
            }
        });
    }
}
