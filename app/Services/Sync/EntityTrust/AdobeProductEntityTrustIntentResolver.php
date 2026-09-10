<?php

namespace App\Services\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustConfirmationMode;
use App\Enums\SyncSemanticOperation;
use App\Models\Product;
use App\Models\SyncConfiguration;
use App\Services\Sync\SyncPreviewConfigurationSnapshotBuilder;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
use App\Support\Connectors\AdobePaaS\AdobeProductExportRunMetadataPreparer;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableDesiredState;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkGuard;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductParentDesiredState;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticPlanner;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;
use App\Support\Sync\EntityTrust\EntityTrustResolvedIntent;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;

final class AdobeProductEntityTrustIntentResolver
{
    public function __construct(
        private readonly ProductExecutionAggregateBuilder $aggregateBuilder,
        private readonly SyncPreviewConfigurationSnapshotBuilder $snapshotBuilder,
        private readonly AdobeProductExportRunMetadataPreparer $metadataPreparer,
        private readonly AdobeProductExportSemanticPlanner $semanticPlanner,
        private readonly AdobeProductDesiredStateCompiler $simpleCompiler,
        private readonly AdobeConfigurableDesiredStateCompiler $configurableCompiler,
    ) {}

    public function resolve(
        SyncConfiguration $configuration,
        Product $product,
        ?string $existingParentSkuHint,
        bool $explicitRelink = false,
    ): EntityTrustResolvedIntent {
        $snapshot = $this->snapshotBuilder->build($configuration, SyncSemanticOperation::Export);
        $aggregates = $this->aggregateBuilder->buildForProductIds(
            $configuration->workspace_id,
            [(string) $product->id],
            $snapshot,
        );

        if ($aggregates === []) {
            throw EntityTrustException::accountConfigurationNotCurrent();
        }

        $aggregate = $aggregates[0];
        $metadata = $this->metadataPreparer->prepareMetadata(
            $configuration->workspace_id,
            $configuration->connector_account_id,
            $snapshot,
        );

        $semanticResult = $this->semanticPlanner->evaluate($aggregate, $snapshot, $metadata);

        if ($semanticResult->hasBlockingFindings()) {
            throw EntityTrustException::accountConfigurationNotCurrent();
        }

        if ($this->isSimplePath($semanticResult)) {
            $desired = $this->simpleCompiler->compileFromSemanticResult($semanticResult);

            return new EntityTrustResolvedIntent(
                mode: EntityTrustConfirmationMode::SimpleVariant,
                configuration: $configuration,
                snapshot: $snapshot,
                aggregate: $aggregate,
                metadata: $metadata,
                semanticResult: $semanticResult,
                simpleDesiredState: $desired,
                localFingerprint: $this->fingerprintSimpleDesired($desired),
            );
        }

        if ($this->isConfigurablePath($semanticResult)) {
            $parentSku = $this->resolveConfigurableParentSku(
                $configuration,
                $product,
                $existingParentSkuHint,
                $explicitRelink,
            );

            $configurable = $this->compileConfigurableWithParentSku(
                $semanticResult,
                $configuration->workspace_id,
                $metadata,
                $parentSku,
            );

            $childDesired = [];

            foreach ($configurable->activeChildVariantIds as $variantId) {
                $childDesired[$variantId] = $this->simpleCompiler->compileSimpleChildFromSemanticResult(
                    $semanticResult,
                    $variantId,
                );
            }

            return new EntityTrustResolvedIntent(
                mode: EntityTrustConfirmationMode::ConfigurableExistingParent,
                configuration: $configuration,
                snapshot: $snapshot,
                aggregate: $aggregate,
                metadata: $metadata,
                semanticResult: $semanticResult,
                configurableDesiredState: $configurable,
                childDesiredStates: $childDesired,
                existingParentSkuHint: $parentSku,
                localFingerprint: $this->fingerprintConfigurableDesired($configurable, $childDesired),
            );
        }

        throw EntityTrustException::accountConfigurationNotCurrent();
    }

    private function isSimplePath(AdobeProductExportSemanticResult $semanticResult): bool
    {
        if ($semanticResult->operations === []) {
            return false;
        }

        foreach ($semanticResult->operations as $operation) {
            if ($operation->operation !== 'simple_product') {
                return false;
            }
        }

        return count($semanticResult->operations) === 1;
    }

    private function isConfigurablePath(AdobeProductExportSemanticResult $semanticResult): bool
    {
        $hasParent = false;

        foreach ($semanticResult->operations as $operation) {
            if ($operation->operation === 'configurable_parent') {
                $hasParent = true;
            }

            if ($operation->operation === 'simple_product') {
                return false;
            }
        }

        return $hasParent;
    }

    private function resolveConfigurableParentSku(
        SyncConfiguration $configuration,
        Product $product,
        ?string $existingParentSkuHint,
        bool $explicitRelink,
    ): string {
        if ($explicitRelink) {
            if ($existingParentSkuHint === null || trim($existingParentSkuHint) === '') {
                throw EntityTrustException::accountConfigurationNotCurrent();
            }

            return trim($existingParentSkuHint);
        }

        $trustedParent = app(AdobeProductExternalRecordLinkGuard::class)
            ->resolveTrustedParentLinkBySubject(
                $configuration->workspace_id,
                $configuration->connector_account_id,
                $product->id,
            );

        if ($trustedParent->isTrusted()) {
            return $trustedParent->link->external_identifier;
        }

        if ($existingParentSkuHint === null || trim($existingParentSkuHint) === '') {
            throw EntityTrustException::accountConfigurationNotCurrent();
        }

        return trim($existingParentSkuHint);
    }

    private function compileConfigurableWithParentSku(
        AdobeProductExportSemanticResult $semanticResult,
        string $workspaceId,
        AdobeProductExportExecutionMetadata $metadata,
        string $parentSku,
    ): AdobeConfigurableDesiredState {
        $compiled = $this->configurableCompiler->compile($semanticResult, $workspaceId, $metadata);

        $parent = new AdobeProductParentDesiredState(
            productId: $compiled->parent->productId,
            sku: $parentSku,
            name: $compiled->parent->name,
            attributeSetId: $compiled->parent->attributeSetId,
            typeId: $compiled->parent->typeId,
            status: $compiled->parent->status,
            visibility: $compiled->parent->visibility,
            customAttributes: $compiled->parent->customAttributes,
        );

        return new AdobeConfigurableDesiredState(
            productId: $compiled->productId,
            parentSku: $parentSku,
            parent: $parent,
            options: $compiled->options,
            activeChildVariantIds: $compiled->activeChildVariantIds,
            childLinks: $compiled->childLinks,
        );
    }

    private function fingerprintSimpleDesired(AdobeProductDesiredState $desired): string
    {
        return hash('sha256', json_encode([
            'sku' => $desired->sku,
            'name' => $desired->name,
            'attribute_set_id' => $desired->attributeSetId,
            'type_id' => $desired->typeId,
            'status' => $desired->status,
            'visibility' => $desired->visibility,
            'price' => $desired->price,
            'custom' => $desired->customAttributes,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, AdobeProductDesiredState>  $childDesired
     */
    private function fingerprintConfigurableDesired(
        AdobeConfigurableDesiredState $configurable,
        array $childDesired,
    ): string {
        $children = [];

        foreach ($configurable->activeChildVariantIds as $variantId) {
            $desired = $childDesired[$variantId] ?? null;

            if ($desired === null) {
                continue;
            }

            $children[$variantId] = [
                'sku' => $desired->sku,
                'name' => $desired->name,
                'attribute_set_id' => $desired->attributeSetId,
                'type_id' => $desired->typeId,
                'status' => $desired->status,
                'visibility' => $desired->visibility,
                'price' => $desired->price,
                'custom' => $desired->customAttributes,
            ];
        }

        ksort($children);

        return hash('sha256', json_encode([
            'parent' => [
                'sku' => $configurable->parent->sku,
                'name' => $configurable->parent->name,
                'attribute_set_id' => $configurable->parent->attributeSetId,
                'type_id' => $configurable->parent->typeId,
                'status' => $configurable->parent->status,
                'visibility' => $configurable->parent->visibility,
                'custom' => $configurable->parent->customAttributes,
            ],
            'children' => $children,
        ], JSON_THROW_ON_ERROR));
    }
}
