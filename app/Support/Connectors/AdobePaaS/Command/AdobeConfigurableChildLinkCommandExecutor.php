<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;

final class AdobeConfigurableChildLinkCommandExecutor
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
        private readonly AdobeConfigurableRemoteOptionStateReader $optionStateReader,
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
    ) {}

    public function execute(
        AdobeConfigurableCommandInput $input,
        AdobeConfigurableChildLinkDesiredState $desiredLink,
    ): AdobeConfigurableCommandEvidence {
        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
        $parentSku = $input->desiredState->parentSku;

        [$childrenGetResult] = $this->remoteStateClient->getConfigurableChildren($context, $parentSku);
        $childSkus = $this->optionStateReader->readChildSkus($childrenGetResult);

        if ($childSkus === null) {
            return $this->unknownOrAmbiguous('configurable_children_get_untrusted', $parentSku, $desiredLink);
        }

        if (count(array_keys($childSkus, $desiredLink->childSku, true)) > 1) {
            return $this->unknownOrAmbiguous('configurable_child_link_duplicate_remote_sku', $parentSku, $desiredLink);
        }

        if (in_array($desiredLink->childSku, $childSkus, true)) {
            return $this->knownApplied('configurable_child_link_no_op', $parentSku, $desiredLink);
        }

        if (! $this->permitsConsequentialWrite($input)) {
            return $this->knownNotApplied('writer_lease_expired_before_consequential_write', $parentSku, $desiredLink);
        }

        [$postResult, $postTransportException] = $this->remoteStateClient->postConfigurableChildLink(
            $context,
            $parentSku,
            $desiredLink->childSku,
        );

        [$reconciliationGetResult] = $this->remoteStateClient->getConfigurableChildren($context, $parentSku);
        $reconciledChildSkus = $this->optionStateReader->readChildSkus($reconciliationGetResult);

        if ($reconciledChildSkus === null) {
            return $this->unknownOrAmbiguous(
                'configurable_child_link_reconciliation_inconclusive',
                $parentSku,
                $desiredLink,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
            );
        }

        if (count(array_keys($reconciledChildSkus, $desiredLink->childSku, true)) > 1) {
            return $this->unknownOrAmbiguous(
                'configurable_child_link_duplicate_remote_sku',
                $parentSku,
                $desiredLink,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
            );
        }

        if (! in_array($desiredLink->childSku, $reconciledChildSkus, true)) {
            return $this->unknownOrAmbiguous(
                'configurable_child_link_reconciliation_missing',
                $parentSku,
                $desiredLink,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
            );
        }

        return $this->knownApplied(
            'configurable_child_link_reconciled',
            $parentSku,
            $desiredLink,
            consequentialWriteAttempts: 1,
            reconciliationGetAttempts: 1,
        );
    }

    public function executeTrustedRelinkOnly(
        AdobeConfigurableCommandInput $input,
        AdobeConfigurableChildLinkDesiredState $desiredLink,
    ): AdobeConfigurableCommandEvidence {
        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
        $parentSku = $input->desiredState->parentSku;

        [$childrenGetResult] = $this->remoteStateClient->getConfigurableChildren($context, $parentSku);
        $childSkus = $this->optionStateReader->readChildSkus($childrenGetResult);

        if ($childSkus === null) {
            return $this->unknownOrAmbiguous('configurable_children_get_untrusted', $parentSku, $desiredLink);
        }

        if (count(array_keys($childSkus, $desiredLink->childSku, true)) > 1) {
            return $this->unknownOrAmbiguous('configurable_child_link_duplicate_remote_sku', $parentSku, $desiredLink);
        }

        $alreadyPresent = in_array($desiredLink->childSku, $childSkus, true);
        $parentLookup = $this->linkGuard->resolveTrustedParentLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $input->desiredState->productId,
        );

        if ($parentLookup->isAmbiguous()) {
            return $this->unknownOrAmbiguous('ambiguous_parent_identity_links', $parentSku, $desiredLink);
        }

        if (! $parentLookup->isTrusted() || $parentLookup->link === null) {
            return $this->knownNotApplied('trusted_parent_link_required_for_child_relink', $parentSku, $desiredLink);
        }

        $trustedParentSku = $parentLookup->link->external_identifier;
        $trustedParentEntityId = $this->parseLogicalEntityId(
            (string) $parentLookup->link->external_record_discriminator,
        );

        if (! is_string($trustedParentSku)
            || $trustedParentSku !== $parentSku
            || $trustedParentEntityId === null
            || $this->linkGuard->hasParentSkuCrossSubjectCollision(
                $input->workspaceId,
                $input->connectorAccountId,
                $parentSku,
                $input->desiredState->productId,
            )
            || $this->linkGuard->hasParentDiscriminatorCrossSubjectCollision(
                $input->workspaceId,
                $input->connectorAccountId,
                (string) $trustedParentEntityId,
                $input->desiredState->productId,
            )
        ) {
            return $this->knownNotApplied('trusted_parent_identity_not_safe_for_child_relink', $parentSku, $desiredLink);
        }

        $childLookup = $this->linkGuard->resolveTrustedVariantLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredLink->variantId,
        );

        if ($childLookup->isAmbiguous()) {
            return $this->unknownOrAmbiguous('ambiguous_child_identity_links', $parentSku, $desiredLink);
        }

        if (! $childLookup->isTrusted() || $childLookup->link === null) {
            return $this->knownNotApplied('trusted_child_link_required_for_relink', $parentSku, $desiredLink);
        }

        $trustedChildSku = $childLookup->link->external_identifier;
        $trustedChildEntityId = $this->parseLogicalEntityId(
            (string) $childLookup->link->external_record_discriminator,
        );

        if (! is_string($trustedChildSku)
            || $trustedChildSku !== $desiredLink->childSku
            || $trustedChildEntityId === null
            || $this->linkGuard->hasCrossSubjectCollision(
                $input->workspaceId,
                $input->connectorAccountId,
                $desiredLink->childSku,
                $desiredLink->variantId,
            )
            || $this->linkGuard->hasVariantDiscriminatorCrossSubjectCollision(
                $input->workspaceId,
                $input->connectorAccountId,
                (string) $trustedChildEntityId,
                $desiredLink->variantId,
            )
        ) {
            return $this->knownNotApplied('trusted_child_identity_not_safe_for_relink', $parentSku, $desiredLink);
        }

        $parentRead = $this->remoteStateClient->getParentWithContext($context, $parentSku);
        if ($parentRead->classification !== AdobeProductRemoteGetClassification::Found
            || $parentRead->observedState === null
        ) {
            return $this->unknownOrAmbiguous('configurable_parent_pre_relink_read_untrusted', $parentSku, $desiredLink);
        }

        if ($parentRead->observedState->entityId !== $trustedParentEntityId
            || $parentRead->observedState->typeId !== 'configurable'
        ) {
            return $this->knownNotApplied('configurable_parent_pre_relink_identity_mismatch', $parentSku, $desiredLink);
        }

        $childRead = $this->remoteStateClient->getProductWithContext($context, $desiredLink->childSku);
        if ($childRead->classification !== AdobeProductRemoteGetClassification::Found
            || $childRead->observedState === null
        ) {
            return $this->unknownOrAmbiguous('configurable_child_pre_relink_read_untrusted', $parentSku, $desiredLink);
        }

        if ($childRead->observedState->entityId !== $trustedChildEntityId
            || $childRead->observedState->typeId !== 'simple'
        ) {
            return $this->knownNotApplied('configurable_child_pre_relink_identity_mismatch', $parentSku, $desiredLink);
        }

        if ($alreadyPresent) {
            return $this->knownApplied('configurable_child_link_no_op', $parentSku, $desiredLink);
        }

        [$freshChildrenGetResult] = $this->remoteStateClient->getConfigurableChildren($context, $parentSku);
        $freshChildSkus = $this->optionStateReader->readChildSkus($freshChildrenGetResult);

        if ($freshChildSkus === null) {
            return $this->unknownOrAmbiguous('configurable_children_pre_relink_recheck_untrusted', $parentSku, $desiredLink);
        }

        if (count(array_keys($freshChildSkus, $desiredLink->childSku, true)) > 1) {
            return $this->unknownOrAmbiguous('configurable_child_link_duplicate_remote_sku', $parentSku, $desiredLink);
        }

        if (in_array($desiredLink->childSku, $freshChildSkus, true)) {
            return $this->knownApplied('configurable_child_link_no_op', $parentSku, $desiredLink);
        }

        if (! $this->permitsConsequentialWrite($input)) {
            return $this->knownNotApplied('writer_lease_expired_before_consequential_write', $parentSku, $desiredLink);
        }

        $this->remoteStateClient->postConfigurableChildLink(
            $context,
            $parentSku,
            $desiredLink->childSku,
        );

        [$reconciliationGetResult] = $this->remoteStateClient->getConfigurableChildren($context, $parentSku);
        $reconciledChildSkus = $this->optionStateReader->readChildSkus($reconciliationGetResult);

        if ($reconciledChildSkus === null) {
            return $this->unknownOrAmbiguous(
                'configurable_child_link_reconciliation_inconclusive',
                $parentSku,
                $desiredLink,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
            );
        }

        if (count(array_keys($reconciledChildSkus, $desiredLink->childSku, true)) > 1) {
            return $this->unknownOrAmbiguous(
                'configurable_child_link_duplicate_remote_sku',
                $parentSku,
                $desiredLink,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
            );
        }

        if (! in_array($desiredLink->childSku, $reconciledChildSkus, true)) {
            return $this->unknownOrAmbiguous(
                'configurable_child_link_reconciliation_missing',
                $parentSku,
                $desiredLink,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
            );
        }

        return $this->knownApplied(
            'configurable_child_link_reconciled',
            $parentSku,
            $desiredLink,
            consequentialWriteAttempts: 1,
            reconciliationGetAttempts: 1,
        );
    }

    public function executeNoOpOnly(
        AdobeConfigurableCommandInput $input,
        AdobeConfigurableChildLinkDesiredState $desiredLink,
    ): AdobeConfigurableCommandEvidence {
        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
        $parentSku = $input->desiredState->parentSku;

        [$childrenGetResult] = $this->remoteStateClient->getConfigurableChildren($context, $parentSku);
        $childSkus = $this->optionStateReader->readChildSkus($childrenGetResult);

        if ($childSkus === null) {
            return $this->unknownOrAmbiguous('configurable_children_get_untrusted', $parentSku, $desiredLink);
        }

        if (count(array_keys($childSkus, $desiredLink->childSku, true)) > 1) {
            return $this->unknownOrAmbiguous('configurable_child_link_duplicate_remote_sku', $parentSku, $desiredLink);
        }

        if (in_array($desiredLink->childSku, $childSkus, true)) {
            return $this->knownApplied('configurable_child_link_no_op', $parentSku, $desiredLink);
        }

        return $this->knownNotApplied(
            'configurable_child_link_mutation_not_certified',
            $parentSku,
            $desiredLink,
        );
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

    private function knownNotApplied(
        string $reasonCode,
        string $parentSku,
        AdobeConfigurableChildLinkDesiredState $desiredLink,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'child_link',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
            reasonCode: $reasonCode,
            subjectSku: $desiredLink->childSku,
            variantId: $desiredLink->variantId,
        );
    }

    private function knownApplied(
        string $reasonCode,
        string $parentSku,
        AdobeConfigurableChildLinkDesiredState $desiredLink,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'child_link',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownApplied,
            reasonCode: $reasonCode,
            subjectSku: $desiredLink->childSku,
            variantId: $desiredLink->variantId,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
        );
    }

    private function unknownOrAmbiguous(
        string $reasonCode,
        string $parentSku,
        AdobeConfigurableChildLinkDesiredState $desiredLink,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'child_link',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            reasonCode: $reasonCode,
            subjectSku: $desiredLink->childSku,
            variantId: $desiredLink->variantId,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
        );
    }
}
