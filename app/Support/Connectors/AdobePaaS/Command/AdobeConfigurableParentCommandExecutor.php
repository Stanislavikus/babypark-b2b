<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobePaaSAccessRejectionEvidence;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;

final class AdobeConfigurableParentCommandExecutor
{
    public function __construct(
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
        private readonly AdobeProductRemoteStateComparator $comparator,
    ) {}

    public function execute(AdobeConfigurableCommandInput $input): AdobeConfigurableCommandEvidence
    {
        $desiredState = $input->desiredState->parent;

        if ($this->linkGuard->hasParentSkuCrossSubjectCollision(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredState->sku,
            $desiredState->productId,
        )) {
            return $this->knownNotApplied('external_record_link_collision', $desiredState->sku);
        }

        $trustedLookup = $this->linkGuard->resolveTrustedParentLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredState->productId,
        );

        if ($trustedLookup->isAmbiguous()) {
            return $this->unknownOrAmbiguous('ambiguous_parent_identity_links', $desiredState->sku);
        }

        if (! $trustedLookup->isTrusted() || $trustedLookup->link === null) {
            return $this->knownNotApplied('link_required', $desiredState->sku);
        }

        $trustedSku = $trustedLookup->link->external_identifier;
        if (! is_string($trustedSku) || $trustedSku === '' || $trustedSku !== $desiredState->sku) {
            return $this->knownNotApplied('trusted_parent_link_sku_mismatch', $trustedSku ?: $desiredState->sku, ownershipTrustSatisfied: true);
        }

        $trustedEntityId = $this->parseLogicalEntityId((string) $trustedLookup->link->external_record_discriminator);
        if ($trustedEntityId === null) {
            return $this->knownNotApplied('trusted_parent_discriminator_invalid', $trustedSku, ownershipTrustSatisfied: true);
        }

        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
        $preRead = $this->remoteStateClient->getParentWithContext($context, $trustedSku);

        if ($preRead->classification === AdobeProductRemoteGetClassification::TrustedKnownMissing) {
            return $this->knownNotApplied('linked_remote_parent_missing', $trustedSku, ownershipTrustSatisfied: true);
        }

        $observed = $preRead->observedState;
        if ($preRead->classification !== AdobeProductRemoteGetClassification::Found || $observed === null) {
            return $this->unknownOrAmbiguous('configurable_parent_pre_read_untrusted_or_failed', $trustedSku, ownershipTrustSatisfied: true);
        }

        if ($observed->entityId !== $trustedEntityId) {
            return $this->knownNotApplied('configurable_parent_identity_mismatch', $trustedSku, ownershipTrustSatisfied: true);
        }

        if ($desiredState->typeId !== 'configurable' || $observed->typeId !== 'configurable') {
            return $this->knownNotApplied('configurable_parent_type_mismatch', $trustedSku, ownershipTrustSatisfied: true);
        }

        if ($this->comparator->parentControlledStateMatches($desiredState, $observed)) {
            return $this->knownApplied('configurable_parent_state_already_matches', $trustedSku);
        }

        if (! $this->permitsConsequentialWrite($input)) {
            return $this->knownNotApplied('writer_lease_expired_before_consequential_write', $trustedSku, ownershipTrustSatisfied: true);
        }

        [$writeHttp, $writeTransportException] = $this->remoteStateClient->putParentProduct($context, $desiredState);

        if ($writeTransportException === null && $writeHttp !== null
            && in_array($writeHttp->statusCode, [401, 403], true)
            && AdobePaaSAccessRejectionEvidence::hasStructuredResourceDenial($writeHttp->body)
        ) {
            return $this->knownNotApplied(
                'stock_write_permission_denied',
                $trustedSku,
                consequentialWriteAttempts: 1,
                ownershipTrustSatisfied: true,
                writeAccessClassification: AdobeProductWriteAccessClassification::PermissionDenied,
            );
        }

        $reconciled = $this->remoteStateClient->getParentWithContext($context, $trustedSku);
        $postObserved = $reconciled->observedState;

        if ($reconciled->classification === AdobeProductRemoteGetClassification::Found && $postObserved !== null) {
            if ($postObserved->entityId !== $trustedEntityId || $postObserved->typeId !== 'configurable') {
                return $this->unknownOrAmbiguous(
                    'configurable_parent_post_write_identity_mismatch',
                    $trustedSku,
                    consequentialWriteAttempts: 1,
                    reconciliationGetAttempts: 1,
                    ownershipTrustSatisfied: true,
                );
            }

            if ($this->comparator->parentControlledStateMatches($desiredState, $postObserved)) {
                return $this->knownApplied(
                    'stock_write_verified',
                    $trustedSku,
                    consequentialWriteAttempts: 1,
                    reconciliationGetAttempts: 1,
                );
            }
        }

        return $this->unknownOrAmbiguous(
            'configurable_parent_write_postcondition_unverified',
            $trustedSku,
            consequentialWriteAttempts: 1,
            reconciliationGetAttempts: 1,
            ownershipTrustSatisfied: true,
        );
    }

    private function permitsConsequentialWrite(AdobeConfigurableCommandInput $input): bool
    {
        return $input->consequentialWriteGate === null
            || ($input->consequentialWriteGate->permitsConsequentialWrite()
                && $input->consequentialWriteGate->permitsProductExecution());
    }

    private function parseLogicalEntityId(string $discriminator): ?int
    {
        if (preg_match('/^[1-9][0-9]*$/', $discriminator) !== 1) {
            return null;
        }

        $entityId = (int) $discriminator;

        return (string) $entityId === $discriminator ? $entityId : null;
    }

    private function knownApplied(
        string $reasonCode,
        string $subjectSku,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_parent',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownApplied,
            reasonCode: $reasonCode,
            subjectSku: $subjectSku,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
            ownershipTrustSatisfied: true,
        );
    }

    private function knownNotApplied(
        string $reasonCode,
        ?string $subjectSku = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        bool $ownershipTrustSatisfied = false,
        ?AdobeProductWriteAccessClassification $writeAccessClassification = null,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_parent',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
            reasonCode: $reasonCode,
            subjectSku: $subjectSku,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
            ownershipTrustSatisfied: $ownershipTrustSatisfied,
            writeAccessClassification: $writeAccessClassification,
        );
    }

    private function unknownOrAmbiguous(
        string $reasonCode,
        ?string $subjectSku = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        bool $ownershipTrustSatisfied = false,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_parent',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            reasonCode: $reasonCode,
            subjectSku: $subjectSku,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
            ownershipTrustSatisfied: $ownershipTrustSatisfied,
        );
    }
}
