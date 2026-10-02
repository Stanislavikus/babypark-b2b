<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Models\ProductVariant;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;

final class AdobeConfigurableInactiveLinkedVariantLifecycleExecutor
{
    private const int DISABLED_STATUS = 2;

    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
        private readonly AdobeProductRemoteStateNormalizer $normalizer,
        private readonly AdobeProductRemoteStateComparator $comparator,
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
        private readonly AdobeConfigurableRemoteOptionStateReader $optionStateReader,
    ) {}

    /**
     * @return list<AdobeConfigurableCommandEvidence>
     */
    public function execute(
        AdobeConfigurableCommandInput $input,
    ): array {
        $inactiveVariants = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $input->workspaceId)
            ->where('product_id', $input->desiredState->productId)
            ->where('is_active', false)
            ->orderBy('id')
            ->get();

        if ($inactiveVariants->isEmpty()) {
            return [];
        }

        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
        [$childrenGetResult] = $this->remoteStateClient->getConfigurableChildren(
            $context,
            $input->desiredState->parentSku,
        );
        $linkedChildSkus = $this->optionStateReader->readChildSkus($childrenGetResult);

        if ($linkedChildSkus === null) {
            return [new AdobeConfigurableCommandEvidence(
                commandKind: 'inactive_child_lifecycle',
                appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
                reasonCode: 'configurable_children_get_untrusted_before_inactive_lifecycle',
                subjectSku: $input->desiredState->parentSku,
            )];
        }

        $evidence = [];

        foreach ($inactiveVariants as $variant) {
            $variantId = (string) $variant->id;
            $trustedLookup = $this->linkGuard->resolveTrustedVariantLinkBySubject(
                $input->workspaceId,
                $input->connectorAccountId,
                $variantId,
            );

            if ($trustedLookup->isNone()) {
                continue;
            }

            if ($trustedLookup->isAmbiguous()) {
                $evidence[] = new AdobeConfigurableCommandEvidence(
                    commandKind: 'inactive_child_lifecycle',
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
                    reasonCode: 'ambiguous_inactive_child_identity_links',
                    variantId: $variantId,
                );

                continue;
            }

            $storedSku = $trustedLookup->link->external_identifier;
            $trustedEntityId = $this->parseLogicalEntityId(
                (string) $trustedLookup->link->external_record_discriminator,
            );

            if (! is_string($storedSku)
                || $storedSku === ''
                || $storedSku !== $variant->sku
                || $trustedEntityId === null
                || $this->linkGuard->hasCrossSubjectCollision(
                    $input->workspaceId,
                    $input->connectorAccountId,
                    $storedSku,
                    $variantId,
                )
                || $this->linkGuard->hasVariantDiscriminatorCrossSubjectCollision(
                    $input->workspaceId,
                    $input->connectorAccountId,
                    (string) $trustedEntityId,
                    $variantId,
                )
            ) {
                $evidence[] = new AdobeConfigurableCommandEvidence(
                    commandKind: 'inactive_child_lifecycle',
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                    reasonCode: 'inactive_linked_child_identity_not_safe',
                    subjectSku: is_string($storedSku) ? $storedSku : null,
                    variantId: $variantId,
                );

                continue;
            }

            if (! in_array($storedSku, $linkedChildSkus, true)) {
                continue;
            }

            $initialGet = $this->remoteStateClient->getProductWithContext($context, $storedSku);

            if ($initialGet->classification === AdobeProductRemoteGetClassification::TrustedKnownMissing) {
                $evidence[] = new AdobeConfigurableCommandEvidence(
                    commandKind: 'inactive_child_lifecycle',
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                    reasonCode: 'inactive_linked_child_missing_no_recreate',
                    subjectSku: $storedSku,
                    variantId: $variantId,
                );

                continue;
            }

            if ($initialGet->classification !== AdobeProductRemoteGetClassification::Found
                || $initialGet->observedState === null
            ) {
                $evidence[] = new AdobeConfigurableCommandEvidence(
                    commandKind: 'inactive_child_lifecycle',
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
                    reasonCode: 'inactive_linked_child_get_untrusted',
                    subjectSku: $storedSku,
                    variantId: $variantId,
                );

                continue;
            }

            if ($initialGet->observedState->entityId !== $trustedEntityId
                || $initialGet->observedState->typeId !== 'simple'
            ) {
                $evidence[] = new AdobeConfigurableCommandEvidence(
                    commandKind: 'inactive_child_lifecycle',
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                    reasonCode: 'inactive_linked_child_identity_mismatch',
                    subjectSku: $storedSku,
                    variantId: $variantId,
                );

                continue;
            }

            if ($this->comparator->productStatusMatches(self::DISABLED_STATUS, $initialGet->observedState)) {
                $evidence[] = new AdobeConfigurableCommandEvidence(
                    commandKind: 'inactive_child_lifecycle',
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownApplied,
                    reasonCode: 'inactive_linked_child_already_disabled',
                    subjectSku: $storedSku,
                    variantId: $variantId,
                );

                continue;
            }

            if ($initialGet->mediaRoleLabelMaterializationSafe !== true) {
                $evidence[] = new AdobeConfigurableCommandEvidence(
                    commandKind: 'inactive_child_lifecycle',
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                    reasonCode: 'inactive_linked_child_media_role_label_side_effect_not_safe',
                    subjectSku: $storedSku,
                    variantId: $variantId,
                );

                continue;
            }

            if (! $this->permitsConsequentialWrite($input)) {
                $evidence[] = new AdobeConfigurableCommandEvidence(
                    commandKind: 'inactive_child_lifecycle',
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                    reasonCode: 'writer_lease_expired_before_consequential_write',
                    subjectSku: $storedSku,
                    variantId: $variantId,
                );

                continue;
            }

            $this->remoteStateClient->putProductStatus(
                $context,
                $storedSku,
                self::DISABLED_STATUS,
            );

            $reconciliationGet = $this->remoteStateClient->getProductWithContext($context, $storedSku);

            if ($reconciliationGet->classification !== AdobeProductRemoteGetClassification::Found
                || $reconciliationGet->observedState === null
                || $reconciliationGet->observedState->entityId !== $trustedEntityId
                || $reconciliationGet->observedState->typeId !== 'simple'
                || ! $this->comparator->productStatusMatches(self::DISABLED_STATUS, $reconciliationGet->observedState)
            ) {
                $evidence[] = new AdobeConfigurableCommandEvidence(
                    commandKind: 'inactive_child_lifecycle',
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
                    reasonCode: 'inactive_linked_child_status_reconciliation_inconclusive',
                    subjectSku: $storedSku,
                    variantId: $variantId,
                    consequentialWriteAttempts: 1,
                    reconciliationGetAttempts: 1,
                );

                continue;
            }

            $evidence[] = new AdobeConfigurableCommandEvidence(
                commandKind: 'inactive_child_lifecycle',
                appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownApplied,
                reasonCode: 'inactive_linked_child_disabled',
                subjectSku: $storedSku,
                variantId: $variantId,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
            );
        }

        return $evidence;
    }

    /**
     * @return list<AdobeConfigurableCommandEvidence>
     */
    public function executeNoOpOnly(
        AdobeConfigurableCommandInput $input,
    ): array {
        $inactiveVariants = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $input->workspaceId)
            ->where('product_id', $input->desiredState->productId)
            ->where('is_active', false)
            ->orderBy('id')
            ->get();

        $evidence = [];

        foreach ($inactiveVariants as $variant) {
            $variantId = (string) $variant->id;
            $trustedLookup = $this->linkGuard->resolveTrustedVariantLinkBySubject(
                $input->workspaceId,
                $input->connectorAccountId,
                $variantId,
            );

            if ($trustedLookup->isNone()) {
                continue;
            }

            if ($trustedLookup->isAmbiguous()) {
                $evidence[] = new AdobeConfigurableCommandEvidence(
                    commandKind: 'inactive_child_lifecycle',
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
                    reasonCode: 'ambiguous_inactive_child_identity_links',
                    variantId: $variantId,
                );

                continue;
            }

            $storedSku = $trustedLookup->link->external_identifier;
            $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
            $initialGet = $this->remoteStateClient->getProductWithContext($context, $storedSku);

            if ($initialGet->classification === AdobeProductRemoteGetClassification::TrustedKnownMissing) {
                $evidence[] = new AdobeConfigurableCommandEvidence(
                    commandKind: 'inactive_child_lifecycle',
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                    reasonCode: 'inactive_linked_child_missing_no_recreate',
                    subjectSku: $storedSku,
                    variantId: $variantId,
                );

                continue;
            }

            if ($initialGet->classification !== AdobeProductRemoteGetClassification::Found
                || $initialGet->observedState === null
            ) {
                $evidence[] = new AdobeConfigurableCommandEvidence(
                    commandKind: 'inactive_child_lifecycle',
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
                    reasonCode: 'inactive_linked_child_get_untrusted',
                    subjectSku: $storedSku,
                    variantId: $variantId,
                );

                continue;
            }

            if ($this->comparator->productStatusMatches(self::DISABLED_STATUS, $initialGet->observedState)) {
                $evidence[] = new AdobeConfigurableCommandEvidence(
                    commandKind: 'inactive_child_lifecycle',
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownApplied,
                    reasonCode: 'inactive_linked_child_already_disabled',
                    subjectSku: $storedSku,
                    variantId: $variantId,
                );

                continue;
            }

            $evidence[] = new AdobeConfigurableCommandEvidence(
                commandKind: 'inactive_child_lifecycle',
                appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                reasonCode: 'inactive_linked_child_status_mutation_not_certified',
                subjectSku: $storedSku,
                variantId: $variantId,
            );
        }

        return $evidence;
    }

    /**
     * @return list<string>
     */
    public function linkedInactiveVariantIds(string $workspaceId, string $connectorAccountId, int $productId): array
    {
        $inactiveVariants = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('product_id', $productId)
            ->where('is_active', false)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $linked = [];

        foreach ($inactiveVariants as $variantId) {
            $lookup = $this->linkGuard->resolveTrustedVariantLinkBySubject(
                $workspaceId,
                $connectorAccountId,
                $variantId,
            );

            if ($lookup->isTrusted()) {
                $linked[] = $variantId;
            }
        }

        return $linked;
    }

    private function permitsConsequentialWrite(AdobeConfigurableCommandInput $input): bool
    {
        if ($input->consequentialWriteGate === null) {
            return true;
        }

        return $input->consequentialWriteGate->permitsConsequentialWrite()
            && $input->consequentialWriteGate->permitsProductExecution();
    }

    private function parseLogicalEntityId(string $discriminator): ?int
    {
        if (preg_match('/^[1-9][0-9]*$/', $discriminator) !== 1) {
            return null;
        }

        $entityId = (int) $discriminator;

        return (string) $entityId === $discriminator ? $entityId : null;
    }
}
