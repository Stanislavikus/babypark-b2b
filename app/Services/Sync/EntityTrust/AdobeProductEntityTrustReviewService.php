<?php

namespace App\Services\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustConfirmationMode;
use App\Enums\EntityTrust\EntityTrustFailureReason;
use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\SyncConfigurationLookupService;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeProductCandidateDiscoveryClient;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeProductEntityTrustComparisonBuilder;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeProductEntityTrustVerifiedSubject;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeProductEntityTrustVerifier;
use App\Support\Sync\EntityTrust\EntityTrustMediaSummary;
use App\Support\Sync\EntityTrust\EntityTrustResolvedIntent;
use App\Support\Sync\EntityTrust\EntityTrustReviewResult;
use App\Support\Sync\EntityTrust\EntityTrustSubjectReview;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use App\Support\Sync\Preview\ProductExecutionImageInput;

final class AdobeProductEntityTrustReviewService
{
    public function __construct(
        private readonly AdobeProductEntityTrustAuthorizationService $authorization,
        private readonly SyncConfigurationLookupService $configurationLookup,
        private readonly AdobeProductEntityTrustIntentResolver $intentResolver,
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductEntityTrustVerifier $verifier,
        private readonly AdobeProductEntityTrustComparisonBuilder $comparisonBuilder,
        private readonly AdobeProductEntityTrustReviewEnvelopeService $envelopeService,
        private readonly AdobeProductCandidateDiscoveryClient $candidateDiscovery,
        private readonly AdobeConnectorAccountTargetSnapshotResolver $targetSnapshotResolver,
    ) {}

    public function review(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
        string $productId,
        ?string $existingParentSkuHint = null,
        bool $explicitRelink = false,
    ): EntityTrustReviewResult {
        $this->authorization->assertReviewOrConfirm($actor, $workspace);
        $account = $this->authorization->resolveConnectorAccount($actor, $workspace, $connectorAccountId);

        $configuration = $this->configurationLookup->findProductsDefaultContext($account);

        if ($configuration === null) {
            throw EntityTrustException::accountConfigurationNotCurrent();
        }

        $product = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('id', $productId)
            ->firstOrFail();

        $intent = $this->intentResolver->resolve($configuration, $product, $existingParentSkuHint, $explicitRelink);
        $context = $this->contextFactory->create($workspace->id, $account->id);
        $targetSnapshot = $this->targetSnapshotResolver->resolve($account);

        if ($intent->mode === EntityTrustConfirmationMode::SimpleVariant) {
            return $this->reviewSimple($actor, $account, $product, $intent, $context, $targetSnapshot, $explicitRelink);
        }

        return $this->reviewConfigurable($actor, $account, $product, $intent, $context, $targetSnapshot, $explicitRelink);
    }

    private function reviewSimple(
        User $actor,
        ConnectorAccount $account,
        Product $product,
        EntityTrustResolvedIntent $intent,
        $context,
        $targetSnapshot,
        bool $explicitRelink,
    ): EntityTrustReviewResult {
        $desired = $intent->simpleDesiredState;
        $verified = $this->verifier->verifySimpleOrChild($context, $desired->sku);
        $observed = $verified->candidate->simpleObservedState;

        if ($observed === null) {
            throw EntityTrustException::candidateUntrusted();
        }

        $comparisons = $this->comparisonBuilder->buildSimpleComparisons($desired, $observed);
        $remoteFingerprint = $this->comparisonBuilder->fingerprintComparisons($comparisons);

        $subjectReview = new EntityTrustSubjectReview(
            subjectKey: 'variant:'.$desired->productVariantId,
            expectedSku: $desired->sku,
            expectedMagentoType: 'simple',
            platformName: $desired->name,
            fieldComparisons: $comparisons,
            mediaSummary: $this->mergeMediaSummary($intent->aggregate->imageInput, $verified),
        );

        $token = $this->envelopeService->issue(
            actorUserId: (string) $actor->id,
            workspaceId: $account->workspace_id,
            connectorAccountId: $account->id,
            syncConfigurationId: $intent->configuration->id,
            configurationRevision: $intent->configuration->configuration_revision,
            productId: (string) $product->id,
            mode: EntityTrustConfirmationMode::SimpleVariant,
            localFingerprint: $intent->localFingerprint,
            subjects: [[
                'subject_key' => 'variant:'.$desired->productVariantId,
                'sku' => $desired->sku,
                'type' => 'simple',
                'logical_entity_id' => $verified->logicalEntityId,
                'remote_fingerprint' => $remoteFingerprint,
            ]],
            existingParentSkuHint: null,
            explicitRelink: $explicitRelink,
            targetSnapshot: $targetSnapshot,
        );

        return new EntityTrustReviewResult(
            status: EntityTrustFailureReason::ReadyForConfirmation,
            mode: EntityTrustConfirmationMode::SimpleVariant,
            productId: (string) $product->id,
            syncConfigurationId: $intent->configuration->id,
            configurationRevision: $intent->configuration->configuration_revision,
            subjects: [$subjectReview],
            reviewToken: $token,
        );
    }

    private function reviewConfigurable(
        User $actor,
        ConnectorAccount $account,
        Product $product,
        EntityTrustResolvedIntent $intent,
        $context,
        $targetSnapshot,
        bool $explicitRelink,
    ): EntityTrustReviewResult {
        $configurable = $intent->configurableDesiredState;
        $parentVerified = $this->verifier->verifyConfigurableParent($context, $configurable->parentSku);
        $parentObserved = $parentVerified->candidate->parentObservedState;

        if ($parentObserved === null) {
            throw EntityTrustException::candidateUntrusted();
        }

        $subjects = [];
        $envelopeSubjects = [];
        $expectedChildSkus = [];

        $parentComparisons = $this->comparisonBuilder->buildParentComparisons(
            $configurable->parent,
            $parentObserved,
        );
        $parentRemoteFingerprint = $this->comparisonBuilder->fingerprintComparisons($parentComparisons);

        $subjects[] = new EntityTrustSubjectReview(
            subjectKey: 'parent:'.$configurable->productId,
            expectedSku: $configurable->parentSku,
            expectedMagentoType: 'configurable',
            platformName: $configurable->parent->name,
            fieldComparisons: $parentComparisons,
        );

        $envelopeSubjects[] = [
            'subject_key' => 'parent:'.$configurable->productId,
            'sku' => $configurable->parentSku,
            'type' => 'configurable',
            'logical_entity_id' => $parentVerified->logicalEntityId,
            'remote_fingerprint' => $parentRemoteFingerprint,
        ];

        foreach ($configurable->activeChildVariantIds as $variantId) {
            $childDesired = $intent->childDesiredStates[$variantId] ?? null;

            if ($childDesired === null) {
                throw EntityTrustException::accountConfigurationNotCurrent();
            }

            $childVerified = $this->verifier->verifySimpleOrChild($context, $childDesired->sku);
            $childObserved = $childVerified->candidate->simpleObservedState;

            if ($childObserved === null) {
                throw EntityTrustException::candidateUntrusted();
            }

            $childComparisons = $this->comparisonBuilder->buildSimpleComparisons($childDesired, $childObserved);
            $childRemoteFingerprint = $this->comparisonBuilder->fingerprintComparisons($childComparisons);
            $expectedChildSkus[] = $childDesired->sku;

            $subjects[] = new EntityTrustSubjectReview(
                subjectKey: 'variant:'.$variantId,
                expectedSku: $childDesired->sku,
                expectedMagentoType: 'simple',
                platformName: $childDesired->name,
                fieldComparisons: $childComparisons,
            );

            $envelopeSubjects[] = [
                'subject_key' => 'variant:'.$variantId,
                'sku' => $childDesired->sku,
                'type' => 'simple',
                'logical_entity_id' => $childVerified->logicalEntityId,
                'remote_fingerprint' => $childRemoteFingerprint,
            ];
        }

        $extraChildrenDiscovery = $this->candidateDiscovery->discoverExtraRemoteChildSkus(
            $context,
            $configurable->parentSku,
            $expectedChildSkus,
        );

        $token = $this->envelopeService->issue(
            actorUserId: (string) $actor->id,
            workspaceId: $account->workspace_id,
            connectorAccountId: $account->id,
            syncConfigurationId: $intent->configuration->id,
            configurationRevision: $intent->configuration->configuration_revision,
            productId: (string) $product->id,
            mode: EntityTrustConfirmationMode::ConfigurableExistingParent,
            localFingerprint: $intent->localFingerprint,
            subjects: $envelopeSubjects,
            existingParentSkuHint: $intent->existingParentSkuHint,
            explicitRelink: $explicitRelink,
            targetSnapshot: $targetSnapshot,
        );

        return new EntityTrustReviewResult(
            status: EntityTrustFailureReason::ReadyForConfirmation,
            mode: EntityTrustConfirmationMode::ConfigurableExistingParent,
            productId: (string) $product->id,
            syncConfigurationId: $intent->configuration->id,
            configurationRevision: $intent->configuration->configuration_revision,
            subjects: $subjects,
            reviewToken: $token,
            extraRemoteChildSkus: $extraChildrenDiscovery->extraChildSkus,
            extraRemoteChildrenAvailable: $extraChildrenDiscovery->isAvailable,
        );
    }

    private function mergeMediaSummary(
        ProductExecutionImageInput $imageInput,
        AdobeProductEntityTrustVerifiedSubject $verified,
    ): EntityTrustMediaSummary {
        $platform = $this->comparisonBuilder->buildPlatformMediaSummary($imageInput);

        return new EntityTrustMediaSummary(
            declaredImageCount: $platform->declaredImageCount,
            declaredRolesSummary: $platform->declaredRolesSummary,
            remoteImageEntryCount: null,
            remoteRolesSummary: null,
        );
    }
}
