<?php

namespace App\Services\ProductStructure;

use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\ProductStructure\ProductTypeChangeImpact;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

final class ProductTypeBulkMutationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly ProductTypeMutationService $mutationService,
    ) {}

    /**
     * @param  list<ProductTypeChangeImpact>  $impacts
     * @return array{succeeded_product_ids: list<int>, failed: list<array{product_id:int,error_class:string}>}
     */
    public function changeMany(User $actor, Workspace $workspace, ProductType $targetType, array $impacts): array
    {
        if (! $this->authorization->allows($actor, $workspace, WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $succeeded = [];
        $failed = [];

        foreach ($impacts as $impact) {
            try {
                $product = Product::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($impact->productId)
                    ->firstOrFail();
                $this->mutationService->change($actor, $workspace, $product, $targetType, $impact);
                $succeeded[] = $impact->productId;
            } catch (AuthorizationException $e) {
                throw $e;
            } catch (Throwable $e) {
                $failed[] = ['product_id' => $impact->productId, 'error_class' => $e::class];
            }
        }

        sort($succeeded);

        return ['succeeded_product_ids' => $succeeded, 'failed' => $failed];
    }
}
