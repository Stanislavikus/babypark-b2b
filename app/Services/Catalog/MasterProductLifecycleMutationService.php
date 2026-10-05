<?php

namespace App\Services\Catalog;

use App\Enums\ProductLifecycleStatus;
use App\Exceptions\Catalog\MasterProductLifecycleMutationException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class MasterProductLifecycleMutationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    public function transition(
        User $actor,
        Workspace $workspace,
        Product $product,
        ProductLifecycleStatus $expected,
        ProductLifecycleStatus $target,
    ): Product {
        if (! in_array($target, [ProductLifecycleStatus::Active, ProductLifecycleStatus::Archived], true)) {
            throw MasterProductLifecycleMutationException::unsupportedTarget();
        }

        return DB::transaction(function () use ($actor, $workspace, $product, $expected, $target): Product {
            $lockedWorkspace = Workspace::query()
                ->whereKey($workspace->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_PRODUCTS)) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            $lockedProduct = Product::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedVariants = ProductVariant::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('product_id', $lockedProduct->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'onec_guid']);

            if (filled($lockedProduct->onec_guid) || $lockedVariants->contains(fn (ProductVariant $variant): bool => filled($variant->onec_guid))) {
                throw MasterProductLifecycleMutationException::sourceOwnedReadOnly();
            }

            $current = $lockedProduct->lifecycle_status
                ?? ($lockedProduct->is_active
                    ? ProductLifecycleStatus::Active
                    : ProductLifecycleStatus::Archived);

            if ($current !== $expected) {
                throw MasterProductLifecycleMutationException::staleState();
            }

            if ($current === $target) {
                return $lockedProduct;
            }

            $lockedProduct->update([
                'lifecycle_status' => $target,
            ]);

            return $lockedProduct->refresh();
        });
    }
}
