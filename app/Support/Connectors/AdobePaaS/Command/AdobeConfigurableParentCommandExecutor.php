<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorTransportException;

final class AdobeConfigurableParentCommandExecutor
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
        private readonly AdobeProductRemoteStateComparator $comparator,
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
        private readonly AdobeProductExternalRecordLinkPersistence $linkPersister,
        private readonly AdobeProductOwnershipTrustPolicy $ownershipTrustPolicy,
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

        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);

        if ($trustedLookup->isTrusted()) {
            $storedExternalSku = $trustedLookup->link->external_identifier;

            if ($storedExternalSku !== $desiredState->sku) {
                return $this->unknownOrAmbiguous(
                    'linked_parent_identity_drift_requires_adobe_validation',
                    $storedExternalSku,
                );
            }

            return $this->executeTrustedLinkUpdate($input, $context, $desiredState, $storedExternalSku);
        }

        return $this->executeNoLinkCreate($input, $context, $desiredState);
    }

    private function executeTrustedLinkUpdate(
        AdobeConfigurableCommandInput $input,
        AdobePaaSRequestContext $context,
        AdobeProductParentDesiredState $desiredState,
        string $linkedExternalSku,
    ): AdobeConfigurableCommandEvidence {
        $initialGet = $this->remoteStateClient->getParentWithContext($context, $linkedExternalSku);

        if ($initialGet->classification === AdobeProductRemoteGetClassification::TrustedKnownMissing) {
            return $this->knownNotApplied('linked_parent_remote_missing', $linkedExternalSku);
        }

        if ($initialGet->classification !== AdobeProductRemoteGetClassification::Found
            || $initialGet->observedState === null
        ) {
            return $this->unknownOrAmbiguous('linked_parent_remote_get_untrusted', $linkedExternalSku);
        }

        if ($this->comparator->parentControlledStateMatches($desiredState, $initialGet->observedState)) {
            return $this->knownApplied('trusted_parent_no_op', $linkedExternalSku, ownershipTrustSatisfied: true);
        }

        if (! $this->permitsConsequentialWrite($input)) {
            return $this->knownNotApplied('writer_lease_expired_before_consequential_write', $linkedExternalSku);
        }

        [$putResult, $putTransportException] = $this->remoteStateClient->putParentProduct($context, $desiredState);

        if ($this->shouldReconcileAfterWrite($putResult, $putTransportException)) {
            return $this->reconcileAfterWrite(
                $input,
                $context,
                $desiredState,
                $linkedExternalSku,
                consequentialWriteAttempts: 1,
                reasonCode: 'trusted_parent_put_inconclusive',
                allowLinkPersistence: false,
                ownershipTrustSatisfied: true,
            );
        }

        return $this->reconcileAfterWrite(
            $input,
            $context,
            $desiredState,
            $linkedExternalSku,
            consequentialWriteAttempts: 1,
            reasonCode: 'trusted_parent_put_reconciled',
            allowLinkPersistence: false,
            ownershipTrustSatisfied: true,
        );
    }

    private function executeNoLinkCreate(
        AdobeConfigurableCommandInput $input,
        AdobePaaSRequestContext $context,
        AdobeProductParentDesiredState $desiredState,
    ): AdobeConfigurableCommandEvidence {
        $initialGet = $this->remoteStateClient->getParentWithContext($context, $desiredState->sku);

        if ($initialGet->classification === AdobeProductRemoteGetClassification::Found) {
            return $this->knownNotApplied('remote_found_without_trusted_parent_link', $desiredState->sku);
        }

        if ($initialGet->classification !== AdobeProductRemoteGetClassification::TrustedKnownMissing) {
            return $this->unknownOrAmbiguous('initial_parent_get_untrusted', $desiredState->sku);
        }

        if (! $this->permitsConsequentialWrite($input)) {
            return $this->knownNotApplied('writer_lease_expired_before_consequential_write', $desiredState->sku);
        }

        [$postResult, $postTransportException] = $this->remoteStateClient->postParentProduct($context, $desiredState);

        if ($this->shouldReconcileAfterWrite($postResult, $postTransportException)) {
            return $this->reconcileAfterWrite(
                $input,
                $context,
                $desiredState,
                $desiredState->sku,
                consequentialWriteAttempts: 1,
                reasonCode: 'no_link_parent_post_inconclusive',
                allowLinkPersistence: true,
            );
        }

        if (! $this->responseBodyConfirmsSku($postResult, $desiredState->sku)) {
            return $this->reconcileAfterWrite(
                $input,
                $context,
                $desiredState,
                $desiredState->sku,
                consequentialWriteAttempts: 1,
                reasonCode: 'no_link_parent_post_inconclusive_body',
                allowLinkPersistence: true,
            );
        }

        return $this->reconcileAfterWrite(
            $input,
            $context,
            $desiredState,
            $desiredState->sku,
            consequentialWriteAttempts: 1,
            reasonCode: 'no_link_parent_post_reconciled',
            allowLinkPersistence: true,
        );
    }

    private function reconcileAfterWrite(
        AdobeConfigurableCommandInput $input,
        AdobePaaSRequestContext $context,
        AdobeProductParentDesiredState $desiredState,
        string $sku,
        int $consequentialWriteAttempts,
        string $reasonCode,
        bool $allowLinkPersistence,
        bool $ownershipTrustSatisfied = false,
    ): AdobeConfigurableCommandEvidence {
        $reconciliationGet = $this->remoteStateClient->getParentWithContext($context, $sku);

        if ($reconciliationGet->classification !== AdobeProductRemoteGetClassification::Found
            || $reconciliationGet->observedState === null
        ) {
            return $this->unknownOrAmbiguous(
                $reasonCode.'_reconciliation_inconclusive',
                $sku,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: 1,
            );
        }

        if (! $this->comparator->parentControlledStateMatches($desiredState, $reconciliationGet->observedState)) {
            return $this->unknownOrAmbiguous(
                $reasonCode.'_reconciliation_mismatch',
                $sku,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: 1,
            );
        }

        $linkPersisted = false;
        $ownershipProven = $ownershipTrustSatisfied;

        if ($allowLinkPersistence
            && $this->ownershipTrustPolicy->canPersistNewParentLink($desiredState, $reconciliationGet->observedState)
        ) {
            try {
                $this->linkPersister->persistTrustedParentLink(
                    $input->workspaceId,
                    $input->connectorAccountId,
                    $desiredState,
                );
                $linkPersisted = true;
                $ownershipProven = true;
            } catch (AdobeProductExternalRecordLinkPersistenceException) {
                return $this->unknownOrAmbiguous(
                    $reasonCode.'_link_persistence_failed',
                    $sku,
                    consequentialWriteAttempts: $consequentialWriteAttempts,
                    reconciliationGetAttempts: 1,
                );
            }
        }

        if (! $ownershipProven) {
            return $this->unknownOrAmbiguous(
                $reasonCode.'_ownership_not_proven',
                $sku,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: 1,
            );
        }

        return $this->knownApplied(
            $reasonCode,
            $sku,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: 1,
            ownershipTrustSatisfied: $ownershipProven,
            externalRecordLinkPersisted: $linkPersisted,
        );
    }

    private function shouldReconcileAfterWrite(
        ?ConnectorHttpResult $httpResult,
        ?ConnectorTransportException $transportException,
    ): bool {
        if ($transportException !== null || $httpResult === null) {
            return true;
        }

        return $httpResult->statusCode < 200 || $httpResult->statusCode >= 300;
    }

    private function responseBodyConfirmsSku(?ConnectorHttpResult $httpResult, string $expectedSku): bool
    {
        if ($httpResult === null) {
            return false;
        }

        $payload = json_decode($httpResult->body, true);

        if (! is_array($payload)) {
            return false;
        }

        $product = $payload['product'] ?? $payload;
        $sku = is_array($product) ? ($product['sku'] ?? null) : null;

        return is_string($sku) && $sku === $expectedSku;
    }

    private function permitsConsequentialWrite(AdobeConfigurableCommandInput $input): bool
    {
        if ($input->consequentialWriteGate === null) {
            return true;
        }

        return $input->consequentialWriteGate->permitsConsequentialWrite();
    }

    private function knownNotApplied(string $reasonCode, ?string $subjectSku = null): AdobeConfigurableCommandEvidence
    {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_parent',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
            reasonCode: $reasonCode,
            subjectSku: $subjectSku,
        );
    }

    private function knownApplied(
        string $reasonCode,
        ?string $subjectSku = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        bool $ownershipTrustSatisfied = false,
        bool $externalRecordLinkPersisted = false,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_parent',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownApplied,
            reasonCode: $reasonCode,
            subjectSku: $subjectSku,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
            externalRecordLinkPersisted: $externalRecordLinkPersisted,
            ownershipTrustSatisfied: $ownershipTrustSatisfied,
        );
    }

    private function unknownOrAmbiguous(
        string $reasonCode,
        ?string $subjectSku = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_parent',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            reasonCode: $reasonCode,
            subjectSku: $subjectSku,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
        );
    }
}
