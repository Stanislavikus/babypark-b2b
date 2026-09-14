<?php

namespace App\Services\ProductStructure;

use App\Models\Product;
use App\Models\ProductActiveOptionalGroup;
use App\Models\ProductType;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\ProductStructure\Exceptions\ProductStructureInvariantException;
use App\Support\ProductStructure\Exceptions\ProductTypeChangeStaleException;
use App\Support\ProductStructure\ProductTypeChangeImpact;
use App\Support\ProductStructure\ProductTypeMutationResult;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ProductTypeMutationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly ProductTypeChangeImpactService $impactService,
    ) {}

    public function change(
        User $actor,
        Workspace $workspace,
        Product $product,
        ProductType $targetType,
        ProductTypeChangeImpact $reviewedImpact,
    ): ProductTypeMutationResult {
        return DB::transaction(function () use ($actor, $workspace, $product, $targetType, $reviewedImpact): ProductTypeMutationResult {
            $lockedWorkspace = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE)) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            if ($reviewedImpact->workspaceId !== (string) $workspace->id || $reviewedImpact->productId !== (int) $product->id) {
                throw ProductTypeChangeStaleException::previewIsStale();
            }

            $typeIds = [$reviewedImpact->fromProductTypeId, $targetType->id];
            sort($typeIds);
            $lockedTypes = ProductType::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereIn('id', array_values(array_unique($typeIds)))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedTarget = $lockedTypes->get((string) $targetType->id);
            if (! $lockedTarget instanceof ProductType || $lockedTarget->status !== 'active') {
                throw new ProductStructureInvariantException('Target ProductType must be active and belong to the Product workspace.');
            }

            $lockedProduct = Product::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedProduct->product_type_id !== $reviewedImpact->fromProductTypeId) {
                throw ProductTypeChangeStaleException::previewIsStale();
            }

            $freshImpact = $this->impactService->preview($lockedProduct, $lockedTarget);
            if (! hash_equals($reviewedImpact->fingerprint(), $freshImpact->fingerprint())) {
                throw ProductTypeChangeStaleException::previewIsStale();
            }

            $validPlacementIds = $lockedTarget->groupPlacements()->withoutGlobalScopes()->pluck('id')->all();
            $invalidOverrides = ProductActiveOptionalGroup::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('product_id', $lockedProduct->id);
            if ($validPlacementIds !== []) {
                $invalidOverrides->whereNotIn('product_type_group_placement_id', $validPlacementIds);
            }
            $invalidOverrideCount = $invalidOverrides->count();
            $invalidOverrides->delete();

            $lockedProduct->setAttribute('product_type_id', $lockedTarget->id);
            $lockedProduct->save();

            return new ProductTypeMutationResult(
                workspaceId: (string) $workspace->id,
                actorId: (int) $actor->id,
                productId: (int) $lockedProduct->id,
                fromProductTypeId: $reviewedImpact->fromProductTypeId,
                toProductTypeId: (string) $lockedTarget->id,
                impactFingerprint: $reviewedImpact->fingerprint(),
                invalidOptionalOverridesRemoved: $invalidOverrideCount,
            );
        });
    }
}
