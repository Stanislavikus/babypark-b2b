<?php

namespace App\Services\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustConfirmationMode;
use App\Enums\EntityTrust\EntityTrustFailureReason;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Sync\SyncConfigurationLookupService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshot;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeProductEntityTrustComparisonBuilder;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeProductEntityTrustVerifiedSubject;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeProductEntityTrustVerifier;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeProductMerchantConfirmedLinkPersister;
use App\Support\Sync\EntityTrust\EntityTrustConfirmationResult;
use App\Support\Sync\EntityTrust\EntityTrustResolvedIntent;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Support\Facades\DB;

final class AdobeProductEntityTrustConfirmationService
{
    public function __construct(
        private readonly AdobeProductEntityTrustAuthorizationService $authorization,
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly SyncConfigurationLookupService $configurationLookup,
        private readonly AdobeProductEntityTrustIntentResolver $intentResolver,
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductEntityTrustVerifier $verifier,
        private readonly AdobeProductEntityTrustComparisonBuilder $comparisonBuilder,
        private readonly AdobeProductEntityTrustReviewEnvelopeService $envelopeService,
        private readonly AdobeProductMerchantConfirmedLinkPersister $merchantPersister,
        private readonly AdobeConnectorAccountTargetSnapshotResolver $targetSnapshotResolver,
    ) {}

    public function confirm(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
        string $productId,
        string $reviewToken,
        ?string $existingParentSkuHint = null,
        bool $explicitRelink = false,
    ): EntityTrustConfirmationResult {
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

        $decoded = $this->envelopeService->validate(
            token: $reviewToken,
            actorUserId: (string) $actor->id,
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            syncConfigurationId: $configuration->id,
            configurationRevision: $configuration->configuration_revision,
            productId: (string) $product->id,
            mode: $intent->mode,
            localFingerprint: $intent->localFingerprint,
            existingParentSkuHint: $intent->existingParentSkuHint,
            explicitRelink: $explicitRelink,
        );

        /** @var AdobeConnectorAccountTargetSnapshot $reviewTargetSnapshot */
        $reviewTargetSnapshot = $decoded['_resolved_target_snapshot'];

        $verifiedSubjects = $this->verifyFreshRemoteSubjects($intent, $context, $decoded['subjects']);

        foreach ($verifiedSubjects as $subjectKey => $verified) {
            $envelopeSubject = collect($decoded['subjects'])->firstWhere('subject_key', $subjectKey);

            if (! is_array($envelopeSubject)) {
                throw EntityTrustException::invalidReviewEvidence();
            }

            $envelopeLogicalEntityId = $envelopeSubject['logical_entity_id'] ?? null;

            if (! is_int($envelopeLogicalEntityId) || $envelopeLogicalEntityId !== $verified->logicalEntityId) {
                throw EntityTrustException::remoteChangedSinceReview();
            }

            $freshFingerprint = $this->buildRemoteFingerprint($intent, $subjectKey, $verified);

            if (($envelopeSubject['remote_fingerprint'] ?? null) !== $freshFingerprint) {
                throw EntityTrustException::remoteChangedSinceReview();
            }
        }

        $persistedKeys = DB::transaction(function () use (
            $actor,
            $workspace,
            $account,
            $configuration,
            $product,
            $intent,
            $verifiedSubjects,
            $explicitRelink,
            $reviewTargetSnapshot,
        ): array {
            $lockedWorkspace = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            if (! $this->workspaceAuthorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS)
                || ! $this->workspaceAuthorization->allows($actor, $lockedWorkspace, WorkspacePermissions::RUN_SYNC_LIVE)
            ) {
                throw EntityTrustException::unauthorized();
            }

            $membership = $this->requireActiveMembership($actor, $lockedWorkspace);

            $lockedAccount = ConnectorAccount::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('id', $account->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedAccount->is_enabled) {
                throw EntityTrustException::accountConfigurationNotCurrent();
            }

            $currentTargetSnapshot = $this->targetSnapshotResolver->resolve($lockedAccount);

            if (! $currentTargetSnapshot->equals($reviewTargetSnapshot)) {
                throw EntityTrustException::reviewTargetMismatch();
            }

            $lockedConfiguration = SyncConfiguration::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('connector_account_id', $account->id)
                ->where('id', $configuration->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedConfiguration->configuration_revision !== $configuration->configuration_revision) {
                throw EntityTrustException::localChangedSinceReview();
            }

            $lockedProduct = Product::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('id', $product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $variantIds = array_values(array_filter(array_map(
                static fn (string $key): ?int => str_starts_with($key, 'variant:') ? (int) substr($key, 8) : null,
                array_keys($verifiedSubjects),
            )));

            sort($variantIds);

            $lockedVariants = ProductVariant::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('product_id', $lockedProduct->id)
                ->whereIn('id', $variantIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedVariants->count() !== count($variantIds)) {
                throw EntityTrustException::localChangedSinceReview();
            }

            $this->lockRelevantExternalRecordLinks($workspace->id, $account->id, $lockedProduct->id, $variantIds);

            $freshIntent = $this->intentResolver->resolve(
                $lockedConfiguration,
                $lockedProduct,
                $intent->existingParentSkuHint,
                $explicitRelink,
            );

            if ($freshIntent->localFingerprint !== $intent->localFingerprint) {
                throw EntityTrustException::localChangedSinceReview();
            }

            $persisted = [];

            if ($intent->mode === EntityTrustConfirmationMode::SimpleVariant) {
                $desired = $freshIntent->simpleDesiredState;
                $verified = $verifiedSubjects['variant:'.$desired->productVariantId];
                $variant = $lockedVariants->get((int) $desired->productVariantId);

                if ($variant === null) {
                    throw EntityTrustException::localChangedSinceReview();
                }

                $this->merchantPersister->establishVariantLink(
                    $workspace->id,
                    $account->id,
                    $variant,
                    $verified->sku,
                    $verified->discriminator(),
                    $membership,
                    allowLegacyUpgrade: true,
                    allowRelink: $explicitRelink,
                );

                $persisted[] = 'variant:'.$desired->productVariantId;
            } else {
                $configurable = $freshIntent->configurableDesiredState;
                $parentVerified = $verifiedSubjects['parent:'.$configurable->productId];

                $this->merchantPersister->establishParentLink(
                    $workspace->id,
                    $account->id,
                    $lockedProduct,
                    $parentVerified->sku,
                    $parentVerified->discriminator(),
                    $membership,
                    allowLegacyUpgrade: true,
                    allowRelink: $explicitRelink,
                );

                $persisted[] = 'parent:'.$configurable->productId;

                foreach ($configurable->activeChildVariantIds as $variantId) {
                    $verified = $verifiedSubjects['variant:'.$variantId] ?? null;
                    $childDesired = $freshIntent->childDesiredStates[$variantId] ?? null;
                    $variant = $lockedVariants->get((int) $variantId);

                    if ($verified === null || $childDesired === null || $variant === null) {
                        throw EntityTrustException::localChangedSinceReview();
                    }

                    $this->merchantPersister->establishVariantLink(
                        $workspace->id,
                        $account->id,
                        $variant,
                        $verified->sku,
                        $verified->discriminator(),
                        $membership,
                        allowLegacyUpgrade: true,
                        allowRelink: $explicitRelink,
                    );

                    $persisted[] = 'variant:'.$variantId;
                }
            }

            return $persisted;
        });

        return new EntityTrustConfirmationResult(
            status: $explicitRelink
                ? EntityTrustFailureReason::RelinkCompleted
                : EntityTrustFailureReason::ConfirmationCompleted,
            persistedSubjectKeys: $persistedKeys,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $envelopeSubjects
     * @return array<string, AdobeProductEntityTrustVerifiedSubject>
     */
    private function verifyFreshRemoteSubjects(
        EntityTrustResolvedIntent $intent,
        $context,
        array $envelopeSubjects,
    ): array {
        $verified = [];

        foreach ($envelopeSubjects as $subject) {
            $sku = (string) ($subject['sku'] ?? '');
            $type = (string) ($subject['type'] ?? '');
            $subjectKey = (string) ($subject['subject_key'] ?? '');

            if ($type === 'configurable') {
                $verified[$subjectKey] = $this->verifier->verifyConfigurableParent($context, $sku);
            } else {
                $verified[$subjectKey] = $this->verifier->verifySimpleOrChild($context, $sku);
            }
        }

        return $verified;
    }

    private function buildRemoteFingerprint(
        EntityTrustResolvedIntent $intent,
        string $subjectKey,
        AdobeProductEntityTrustVerifiedSubject $verified,
    ): string {
        if (str_starts_with($subjectKey, 'parent:')) {
            $parent = $intent->configurableDesiredState?->parent;
            $observed = $verified->candidate->parentObservedState;

            if ($parent === null || $observed === null) {
                throw EntityTrustException::candidateUntrusted();
            }

            return $this->comparisonBuilder->fingerprintComparisons(
                $this->comparisonBuilder->buildParentComparisons($parent, $observed),
            );
        }

        $variantId = substr($subjectKey, 8);
        $desired = $intent->mode === EntityTrustConfirmationMode::SimpleVariant
            ? $intent->simpleDesiredState
            : ($intent->childDesiredStates[$variantId] ?? null);
        $observed = $verified->candidate->simpleObservedState;

        if ($desired === null || $observed === null) {
            throw EntityTrustException::candidateUntrusted();
        }

        return $this->comparisonBuilder->fingerprintComparisons(
            $this->comparisonBuilder->buildSimpleComparisons($desired, $observed),
        );
    }

    /**
     * @param  list<int>  $variantIds
     */
    private function lockRelevantExternalRecordLinks(
        string $workspaceId,
        string $connectorAccountId,
        int $productId,
        array $variantIds,
    ): void {
        ExternalRecordLink::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where(function ($query) use ($productId, $variantIds): void {
                $query->where('product_id', $productId)
                    ->orWhereIn('product_variant_id', $variantIds);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function requireActiveMembership(User $actor, Workspace $workspace): WorkspaceUser
    {
        $membership = $this->workspaceAuthorization->activeMembership($actor, $workspace);

        if ($membership === null || ! $membership->is_active) {
            throw EntityTrustException::unauthorized();
        }

        return $membership;
    }
}
