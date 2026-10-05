<?php

namespace App\Services\Sync;

use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetOverride;
use App\Models\AdobeProductCategory;
use App\Models\AdobeProductCategoryOverride;
use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\SyncConfigurationProductSelection;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Sync\AdobeProductEffectiveClassification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ProductMagentoClassificationEditor
{
    public function __construct(
        private readonly AdobeProductClassificationReadService $readService,
        private readonly AdobeProductClassificationMutationService $mutationService,
    ) {}

    /**
     * @return array<string, string>
     */
    public function accountOptions(Product $product): array
    {
        return $this->selectedAccounts($product)
            ->mapWithKeys(fn (ConnectorAccount $account): array => [
                (string) $account->id => (string) $account->name,
            ])
            ->all();
    }

    /**
     * @return array{
     *   account_id:string,
     *   category_mode:'automatic'|'override',
     *   category_ids:list<string>,
     *   attribute_set_mode:'automatic'|'override'|'observed_remote',
     *   attribute_set_id:?string
     * }
     */
    public function formState(Product $product, string $accountId): array
    {
        $account = $this->selectedAccount($product, $accountId);
        $classification = $this->readService->resolve($account, $product);

        $categoryOverrides = AdobeProductCategoryOverride::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('product_id', $product->id)
            ->orderBy('external_category_id')
            ->pluck('external_category_id')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        $attributeOverride = AdobeProductAttributeSetOverride::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('product_id', $product->id)
            ->first();

        return [
            'account_id' => (string) $account->id,
            'category_mode' => $categoryOverrides === [] ? 'automatic' : 'override',
            'category_ids' => $categoryOverrides !== []
                ? $categoryOverrides
                : $classification->externalCategoryIds,
            'attribute_set_mode' => $classification->hasTrustedRemoteSubject
                ? 'observed_remote'
                : ($attributeOverride === null ? 'automatic' : 'override'),
            'attribute_set_id' => $classification->hasTrustedRemoteSubject
                ? $classification->adobeProductAttributeSetId
                : ($attributeOverride?->adobe_product_attribute_set_id
                    ?? $classification->adobeProductAttributeSetId),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function categoryOptions(Product $product, string $accountId): array
    {
        $account = $this->selectedAccount($product, $accountId);

        return AdobeProductCategory::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('connector_account_id', $account->id)
            ->whereNull('missing_since')
            ->where('is_active', true)
            ->where('level', '>=', 2)
            ->orderBy('breadcrumb')
            ->orderBy('external_category_id')
            ->get()
            ->mapWithKeys(function (AdobeProductCategory $category): array {
                $label = filled($category->breadcrumb)
                    ? (string) $category->breadcrumb
                    : (filled($category->name)
                        ? (string) $category->name
                        : (string) $category->external_category_id);

                return [(string) $category->external_category_id => $label];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function attributeSetOptions(Product $product, string $accountId): array
    {
        $account = $this->selectedAccount($product, $accountId);

        return AdobeProductAttributeSet::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('connector_account_id', $account->id)
            ->whereNull('missing_since')
            ->orderBy('name')
            ->orderBy('provider_attribute_set_id')
            ->get()
            ->mapWithKeys(fn (AdobeProductAttributeSet $set): array => [
                (string) $set->id => (string) ($set->name ?: 'Attribute Set '.$set->provider_attribute_set_id),
            ])
            ->all();
    }

    public function effective(Product $product, string $accountId): AdobeProductEffectiveClassification
    {
        return $this->readService->resolve($this->selectedAccount($product, $accountId), $product);
    }

    /**
     * @param array{
     *   account_id:string,
     *   category_mode:string,
     *   category_ids?:list<string|int>,
     *   attribute_set_mode:string,
     *   attribute_set_id?:string|null
     * } $data
     */
    public function apply(
        User $actor,
        Workspace $workspace,
        Product $product,
        array $data,
    ): AdobeProductEffectiveClassification {
        $accountId = trim((string) ($data['account_id'] ?? ''));
        $account = $this->selectedAccount($product, $accountId);

        DB::transaction(function () use ($actor, $workspace, $product, $account, $data): void {
            if (($data['category_mode'] ?? null) === 'override') {
                $this->mutationService->replaceProductCategoryOverrides(
                    $actor,
                    $workspace,
                    $account,
                    $product,
                    $data['category_ids'] ?? [],
                );
            } elseif (($data['category_mode'] ?? null) === 'automatic') {
                $this->mutationService->resetProductCategoryOverrides(
                    $actor,
                    $workspace,
                    $account,
                    $product,
                );
            } else {
                throw new AuthorizationException('Invalid Magento category mode.');
            }

            $currentClassification = $this->readService->resolve($account, $product);

            if ($currentClassification->hasTrustedRemoteSubject) {
                if (($data['attribute_set_mode'] ?? null) !== 'observed_remote') {
                    throw new AuthorizationException('Existing trusted Magento Product Attribute Set is read-only.');
                }

                return;
            }

            if (($data['attribute_set_mode'] ?? null) === 'override') {
                $attributeSetId = trim((string) ($data['attribute_set_id'] ?? ''));
                $attributeSet = AdobeProductAttributeSet::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->where('connector_account_id', $account->id)
                    ->whereKey($attributeSetId)
                    ->whereNull('missing_since')
                    ->first();

                if (! $attributeSet instanceof AdobeProductAttributeSet) {
                    throw new AuthorizationException('Invalid Magento Attribute Set.');
                }

                $this->mutationService->setProductAttributeSetOverride(
                    $actor,
                    $workspace,
                    $account,
                    $product,
                    $attributeSet,
                );
            } elseif (($data['attribute_set_mode'] ?? null) === 'automatic') {
                $this->mutationService->resetProductAttributeSetOverride(
                    $actor,
                    $workspace,
                    $account,
                    $product,
                );
            } else {
                throw new AuthorizationException('Invalid Magento Attribute Set mode.');
            }
        });

        return $this->readService->resolve($account, $product->fresh());
    }

    private function selectedAccount(Product $product, string $accountId): ConnectorAccount
    {
        $account = $this->selectedAccounts($product)
            ->first(fn (ConnectorAccount $candidate): bool => (string) $candidate->id === $accountId);

        if (! $account instanceof ConnectorAccount) {
            throw new AuthorizationException('Magento account is not selected for this Product.');
        }

        return $account;
    }

    /**
     * @return \Illuminate\Support\Collection<int, ConnectorAccount>
     */
    private function selectedAccounts(Product $product): \Illuminate\Support\Collection
    {
        return SyncConfigurationProductSelection::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->with('syncConfiguration.connectorAccount.connectorDefinition')
            ->get()
            ->map(fn (SyncConfigurationProductSelection $selection) => $selection->syncConfiguration?->connectorAccount)
            ->filter(fn ($account): bool => $account instanceof ConnectorAccount
                && $account->connectorDefinition?->code === 'adobe_commerce')
            ->unique('id')
            ->sortBy('name')
            ->values();
    }
}
