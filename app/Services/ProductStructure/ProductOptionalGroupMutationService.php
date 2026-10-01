<?php

namespace App\Services\ProductStructure;

use App\Models\Product;
use App\Models\ProductActiveOptionalGroup;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\ProductVariantAxis;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Catalog\Exceptions\ProductVariantStructureException;
use App\Support\ProductStructure\Exceptions\ProductStructureInvariantException;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ProductOptionalGroupMutationService
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    public function setActive(
        User $actor,
        Workspace $workspace,
        Product $product,
        ProductTypeGroupPlacement $placement,
        bool $active,
    ): void {
        DB::transaction(function () use ($actor, $workspace, $product, $placement, $active): void {
            $lockedWorkspace = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE)) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            $lockedProduct = Product::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedPlacement = ProductTypeGroupPlacement::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereKey($placement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedPlacement->product_type_id !== (string) $lockedProduct->product_type_id) {
                throw new ProductStructureInvariantException('Optional group is not admitted by the Product current ProductType.');
            }
            if (! $lockedPlacement->is_optional) {
                throw new ProductStructureInvariantException('Required ProductType groups cannot be activated or deactivated per Product.');
            }

            if (! $active) {
                $bindingIds = ProductTypeFieldPlacement::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->where('product_type_id', $lockedProduct->product_type_id)
                    ->where('product_type_group_placement_id', $lockedPlacement->id)
                    ->pluck('field_binding_id');

                if ($bindingIds->isNotEmpty() && ProductVariantAxis::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->where('product_id', $lockedProduct->id)
                    ->whereIn('field_binding_id', $bindingIds)
                    ->exists()) {
                    throw ProductVariantStructureException::declaredAxisBlocksStructureChange();
                }
            }

            $query = ProductActiveOptionalGroup::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('product_id', $lockedProduct->id)
                ->where('product_type_group_placement_id', $lockedPlacement->id);

            if ($active === (bool) $lockedPlacement->default_active) {
                $query->delete();

                return;
            }

            $override = $query->lockForUpdate()->first() ?? new ProductActiveOptionalGroup;
            $override->fill([
                'workspace_id' => $workspace->id,
                'product_id' => $lockedProduct->id,
                'product_type_group_placement_id' => $lockedPlacement->id,
                'is_active' => $active,
            ]);
            $override->save();
        });
    }
}
