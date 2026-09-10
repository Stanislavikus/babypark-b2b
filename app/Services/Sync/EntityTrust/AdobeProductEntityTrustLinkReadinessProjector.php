<?php

namespace App\Services\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustReadinessStatus;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\SyncConfigurationLookupService;
use App\Services\Sync\SyncPreviewConfigurationSnapshotBuilder;
use App\Support\Connectors\AdobePaaS\AdobeProductExportRunMetadataPreparer;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkGuard;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticPlanner;
use App\Support\Sync\EntityTrust\EntityTrustLinkReadinessItem;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use App\Support\Sync\Preview\ProductVariantExecutionSlice;

final class AdobeProductEntityTrustLinkReadinessProjector
{
    public function __construct(
        private readonly AdobeProductEntityTrustAuthorizationService $authorization,
        private readonly SyncConfigurationLookupService $configurationLookup,
        private readonly ProductExecutionAggregateBuilder $aggregateBuilder,
        private readonly SyncPreviewConfigurationSnapshotBuilder $snapshotBuilder,
        private readonly AdobeProductExportRunMetadataPreparer $metadataPreparer,
        private readonly AdobeProductExportSemanticPlanner $semanticPlanner,
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
    ) {}

    /**
     * @return list<EntityTrustLinkReadinessItem>
     */
    public function projectForAccount(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
    ): array {
        if (! $this->authorization->canReviewOrConfirm($actor, $workspace)) {
            return [];
        }

        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('id', $connectorAccountId)
            ->where('is_enabled', true)
            ->first();

        if ($account === null) {
            return [];
        }

        $configuration = $this->configurationLookup->findProductsDefaultContext($account);

        if ($configuration === null) {
            return [];
        }

        $products = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $snapshot = $this->snapshotBuilder->build($configuration, SyncSemanticOperation::Export);
        $metadata = $this->metadataPreparer->prepareMetadata(
            $workspace->id,
            $account->id,
            $snapshot,
        );

        $items = [];

        foreach ($products as $product) {
            $items[] = $this->projectProduct(
                $account,
                $product,
                $snapshot,
                $metadata,
            );
        }

        return $items;
    }

    private function projectProduct(
        ConnectorAccount $account,
        Product $product,
        array $snapshot,
        $metadata,
    ): EntityTrustLinkReadinessItem {
        $aggregates = $this->aggregateBuilder->buildForProductIds(
            $account->workspace_id,
            [(string) $product->id],
            $snapshot,
        );

        if ($aggregates === []) {
            return new EntityTrustLinkReadinessItem(
                productId: (string) $product->id,
                productName: $product->name,
                primarySku: null,
                status: EntityTrustReadinessStatus::NoAction,
                isConfigurableFamily: false,
            );
        }

        $aggregate = $aggregates[0];
        $semantic = $this->semanticPlanner->evaluate($aggregate, $snapshot, $metadata);
        $isConfigurable = $this->hasConfigurableParent($semantic);

        if ($isConfigurable) {
            $parentLookup = $this->linkGuard->resolveTrustedParentLinkBySubject(
                $account->workspace_id,
                $account->id,
                $product->id,
            );

            if ($parentLookup->isAmbiguous()) {
                return new EntityTrustLinkReadinessItem(
                    productId: (string) $product->id,
                    productName: $product->name,
                    primarySku: null,
                    status: EntityTrustReadinessStatus::RelinkReviewRequired,
                    isConfigurableFamily: true,
                );
            }

            $childStatuses = [];

            foreach ($aggregate->variants as $variantSlice) {
                $variantLookup = $this->linkGuard->resolveTrustedVariantLinkBySubject(
                    $account->workspace_id,
                    $account->id,
                    (string) $variantSlice->variantId,
                );

                if ($variantLookup->isAmbiguous()) {
                    return new EntityTrustLinkReadinessItem(
                        productId: (string) $product->id,
                        productName: $product->name,
                        primarySku: $this->variantSku($variantSlice),
                        status: EntityTrustReadinessStatus::RelinkReviewRequired,
                        isConfigurableFamily: true,
                    );
                }

                $childStatuses[] = $variantLookup->isTrusted();
            }

            if (! $parentLookup->isTrusted() || in_array(false, $childStatuses, true)) {
                return new EntityTrustLinkReadinessItem(
                    productId: (string) $product->id,
                    productName: $product->name,
                    primarySku: $this->variantSku($aggregate->variants[0]),
                    status: EntityTrustReadinessStatus::InitialLinkRequired,
                    isConfigurableFamily: true,
                );
            }

            return new EntityTrustLinkReadinessItem(
                productId: (string) $product->id,
                productName: $product->name,
                primarySku: $parentLookup->link?->external_identifier,
                status: EntityTrustReadinessStatus::AlreadyConfirmed,
                isConfigurableFamily: true,
            );
        }

        $variant = $aggregate->variants[0] ?? null;

        if ($variant === null) {
            return new EntityTrustLinkReadinessItem(
                productId: (string) $product->id,
                productName: $product->name,
                primarySku: null,
                status: EntityTrustReadinessStatus::NoAction,
                isConfigurableFamily: false,
            );
        }

        $lookup = $this->linkGuard->resolveTrustedVariantLinkBySubject(
            $account->workspace_id,
            $account->id,
            (string) $variant->variantId,
        );

        if ($lookup->isAmbiguous()) {
            return new EntityTrustLinkReadinessItem(
                productId: (string) $product->id,
                productName: $product->name,
                primarySku: $this->variantSku($variant),
                status: EntityTrustReadinessStatus::RelinkReviewRequired,
                isConfigurableFamily: false,
            );
        }

        if (! $lookup->isTrusted()) {
            return new EntityTrustLinkReadinessItem(
                productId: (string) $product->id,
                productName: $product->name,
                primarySku: $this->variantSku($variant),
                status: EntityTrustReadinessStatus::InitialLinkRequired,
                isConfigurableFamily: false,
            );
        }

        return new EntityTrustLinkReadinessItem(
            productId: (string) $product->id,
            productName: $product->name,
            primarySku: $this->variantSku($variant),
            status: EntityTrustReadinessStatus::AlreadyConfirmed,
            isConfigurableFamily: false,
        );
    }

    private function variantSku(ProductVariantExecutionSlice $slice): ?string
    {
        return ProductVariant::withoutWorkspaceScope()->find($slice->variantId)?->sku;
    }

    private function hasConfigurableParent($semantic): bool
    {
        foreach ($semantic->operations as $operation) {
            if ($operation->operation === 'configurable_parent') {
                return true;
            }
        }

        return false;
    }
}
