<?php

namespace App\Services\ProductStructure;

use App\Models\Product;
use App\Models\ProductTypeGroupPlacement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

final class ProductOptionalGroupBulkMutationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly ProductOptionalGroupMutationService $mutationService,
    ) {}

    /**
     * @param  list<int>  $productIds
     * @return array{succeeded_product_ids:list<int>,failed:list<array{product_id:int,error_class:string}>}
     */
    public function setActiveMany(
        User $actor,
        Workspace $workspace,
        array $productIds,
        string $attributeGroupId,
        bool $active,
    ): array {
        if (! $this->authorization->allows($actor, $workspace, WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $succeeded = [];
        $failed = [];
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        sort($ids);

        foreach ($ids as $productId) {
            try {
                $product = Product::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($productId)
                    ->firstOrFail();
                $placement = ProductTypeGroupPlacement::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->where('product_type_id', $product->product_type_id)
                    ->where('attribute_group_id', $attributeGroupId)
                    ->where('is_optional', true)
                    ->firstOrFail();

                $this->mutationService->setActive($actor, $workspace, $product, $placement, $active);
                $succeeded[] = $productId;
            } catch (AuthorizationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $failed[] = [
                    'product_id' => $productId,
                    'error_class' => $exception::class,
                ];
            }
        }

        return [
            'succeeded_product_ids' => $succeeded,
            'failed' => $failed,
        ];
    }
}
