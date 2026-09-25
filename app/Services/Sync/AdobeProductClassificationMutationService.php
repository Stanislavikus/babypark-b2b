<?php

namespace App\Services\Sync;

use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetOverride;
use App\Models\AdobeProductCategoryOverride;
use App\Models\AdobeProductTypeAttributeSetDefault;
use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Sync\Exceptions\AdobeProductClassificationException;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class AdobeProductClassificationMutationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    public function setProductTypeAttributeSetDefault(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        ProductType $productType,
        AdobeProductAttributeSet $attributeSet,
    ): AdobeProductTypeAttributeSetDefault {
        return DB::transaction(function () use ($actor, $workspace, $account, $productType, $attributeSet): AdobeProductTypeAttributeSetDefault {
            $lockedWorkspace = $this->authorizeWorkspace($actor, $workspace);
            $this->assertAdobeAccount($lockedWorkspace, $account);

            $lockedType = ProductType::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->whereKey($productType->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $lockedType instanceof ProductType) {
                throw AdobeProductClassificationException::productTypeUnavailable();
            }

            $lockedSet = $this->lockCurrentAttributeSet($lockedWorkspace, $account, $attributeSet);

            return AdobeProductTypeAttributeSetDefault::withoutWorkspaceScope()->updateOrCreate(
                [
                    'workspace_id' => $lockedWorkspace->id,
                    'connector_account_id' => $account->id,
                    'product_type_id' => $lockedType->id,
                ],
                [
                    'adobe_product_attribute_set_id' => $lockedSet->id,
                ],
            );
        });
    }

    public function resetProductTypeAttributeSetDefault(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        ProductType $productType,
    ): void {
        DB::transaction(function () use ($actor, $workspace, $account, $productType): void {
            $lockedWorkspace = $this->authorizeWorkspace($actor, $workspace);
            $this->assertAdobeAccount($lockedWorkspace, $account);

            $lockedType = ProductType::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->whereKey($productType->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedType instanceof ProductType) {
                throw AdobeProductClassificationException::productTypeUnavailable();
            }

            AdobeProductTypeAttributeSetDefault::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('connector_account_id', $account->id)
                ->where('product_type_id', $lockedType->id)
                ->delete();
        });
    }

    public function setProductAttributeSetOverride(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        Product $product,
        AdobeProductAttributeSet $attributeSet,
    ): AdobeProductAttributeSetOverride {
        return DB::transaction(function () use ($actor, $workspace, $account, $product, $attributeSet): AdobeProductAttributeSetOverride {
            $lockedWorkspace = $this->authorizeWorkspace($actor, $workspace);
            $this->assertAdobeAccount($lockedWorkspace, $account);
            $lockedProduct = $this->lockProduct($lockedWorkspace, $product);
            $lockedSet = $this->lockCurrentAttributeSet($lockedWorkspace, $account, $attributeSet);

            return AdobeProductAttributeSetOverride::withoutWorkspaceScope()->updateOrCreate(
                [
                    'workspace_id' => $lockedWorkspace->id,
                    'connector_account_id' => $account->id,
                    'product_id' => $lockedProduct->id,
                ],
                [
                    'adobe_product_attribute_set_id' => $lockedSet->id,
                ],
            );
        });
    }

    public function resetProductAttributeSetOverride(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        Product $product,
    ): void {
        DB::transaction(function () use ($actor, $workspace, $account, $product): void {
            $lockedWorkspace = $this->authorizeWorkspace($actor, $workspace);
            $this->assertAdobeAccount($lockedWorkspace, $account);
            $lockedProduct = $this->lockProduct($lockedWorkspace, $product);

            AdobeProductAttributeSetOverride::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('connector_account_id', $account->id)
                ->where('product_id', $lockedProduct->id)
                ->delete();
        });
    }

    /**
     * @param  iterable<string|int>  $externalCategoryIds
     * @return list<AdobeProductCategoryOverride>
     */
    public function replaceProductCategoryOverrides(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        Product $product,
        iterable $externalCategoryIds,
    ): array {
        return DB::transaction(function () use ($actor, $workspace, $account, $product, $externalCategoryIds): array {
            $lockedWorkspace = $this->authorizeWorkspace($actor, $workspace);
            $this->assertAdobeAccount($lockedWorkspace, $account);
            $lockedProduct = $this->lockProduct($lockedWorkspace, $product);
            $normalized = $this->normalizeExternalCategoryIds($externalCategoryIds);

            if ($normalized === []) {
                throw AdobeProductClassificationException::categoriesRequired();
            }

            AdobeProductCategoryOverride::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('connector_account_id', $account->id)
                ->where('product_id', $lockedProduct->id)
                ->delete();

            $rows = [];

            foreach ($normalized as $externalCategoryId) {
                $rows[] = AdobeProductCategoryOverride::withoutWorkspaceScope()->create([
                    'workspace_id' => $lockedWorkspace->id,
                    'connector_account_id' => $account->id,
                    'product_id' => $lockedProduct->id,
                    'external_category_id' => $externalCategoryId,
                ]);
            }

            return $rows;
        });
    }

    public function resetProductCategoryOverrides(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        Product $product,
    ): void {
        DB::transaction(function () use ($actor, $workspace, $account, $product): void {
            $lockedWorkspace = $this->authorizeWorkspace($actor, $workspace);
            $this->assertAdobeAccount($lockedWorkspace, $account);
            $lockedProduct = $this->lockProduct($lockedWorkspace, $product);

            AdobeProductCategoryOverride::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('connector_account_id', $account->id)
                ->where('product_id', $lockedProduct->id)
                ->delete();
        });
    }

    private function authorizeWorkspace(User $actor, Workspace $workspace): Workspace
    {
        $lockedWorkspace = Workspace::query()
            ->whereKey($workspace->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $this->authorization->allows(
            $actor,
            $lockedWorkspace,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        )) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $lockedWorkspace;
    }

    private function assertAdobeAccount(Workspace $workspace, ConnectorAccount $account): void
    {
        $freshAccount = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($account->id)
            ->with('connectorDefinition')
            ->first();

        if (! $freshAccount instanceof ConnectorAccount) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        if ($freshAccount->connectorDefinition?->code !== 'adobe_commerce') {
            throw AdobeProductClassificationException::nonAdobeAccount();
        }
    }

    private function lockProduct(Workspace $workspace, Product $product): Product
    {
        $lockedProduct = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($product->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedProduct instanceof Product) {
            throw AdobeProductClassificationException::productUnavailable();
        }

        return $lockedProduct;
    }

    private function lockCurrentAttributeSet(
        Workspace $workspace,
        ConnectorAccount $account,
        AdobeProductAttributeSet $attributeSet,
    ): AdobeProductAttributeSet {
        $lockedSet = AdobeProductAttributeSet::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('connector_account_id', $account->id)
            ->whereKey($attributeSet->id)
            ->whereNull('missing_since')
            ->lockForUpdate()
            ->first();

        if (! $lockedSet instanceof AdobeProductAttributeSet) {
            throw AdobeProductClassificationException::attributeSetUnavailable();
        }

        return $lockedSet;
    }

    /**
     * @param  iterable<string|int>  $externalCategoryIds
     * @return list<string>
     */
    private function normalizeExternalCategoryIds(iterable $externalCategoryIds): array
    {
        $normalized = [];

        foreach ($externalCategoryIds as $externalCategoryId) {
            $value = trim((string) $externalCategoryId);

            if ($value === '' || mb_strlen($value) > 255) {
                throw AdobeProductClassificationException::categoriesRequired();
            }

            $normalized[] = $value;
        }

        $values = array_values(array_unique($normalized, SORT_STRING));
        sort($values, SORT_STRING);

        return $values;
    }
}
