<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorTransportException;

final class AdobeProductSimpleCommandExecutor
{
    public function __construct(
        private readonly AdobeProductDesiredStateCompiler $compiler,
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
        private readonly AdobeProductRemoteStateComparator $comparator,
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
        private readonly AdobeProductExternalRecordLinkPersistence $linkPersister,
        private readonly AdobeProductOwnershipTrustPolicy $ownershipTrustPolicy,
    ) {}

    public function execute(AdobeProductSimpleCommandInput $input): AdobeProductSimpleCommandResult
    {
        if ($input->semanticResult->hasBlockingFindings()) {
            return $this->knownNotApplied('blocking_semantic_findings');
        }

        try {
            $desiredState = $this->compiler->compileFromSemanticResult(
                $input->semanticResult,
            );
        } catch (AdobeProductCommandCompilationException) {
            return $this->knownNotApplied('semantic_compilation_failed');
        }

        return $this->executeDesiredState($input, $desiredState);
    }

    public function executeSimpleChild(
        AdobeProductSimpleCommandInput $input,
        string $variantId,
    ): AdobeProductSimpleCommandResult {
        if ($input->semanticResult->hasBlockingFindings()) {
            return $this->knownNotApplied('blocking_semantic_findings');
        }

        try {
            $desiredState = $this->compiler->compileSimpleChildFromSemanticResult(
                $input->semanticResult,
                $variantId,
            );
        } catch (AdobeProductCommandCompilationException) {
            return $this->knownNotApplied('semantic_compilation_failed');
        }

        return $this->executeDesiredState($input, $desiredState);
    }

    private function executeDesiredState(
        AdobeProductSimpleCommandInput $input,
        AdobeProductDesiredState $desiredState,
    ): AdobeProductSimpleCommandResult {
        if ($input->adobeBaseCurrency === null || $input->adobeBaseCurrency === '') {
            return $this->knownNotApplied('currency_evidence_missing');
        }

        if ($input->adobeBaseCurrency !== $desiredState->priceCurrency) {
            return $this->knownNotApplied('currency_mismatch');
        }

        if ($this->linkGuard->hasCrossSubjectCollision(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredState->sku,
            $desiredState->productVariantId,
        )) {
            return $this->knownNotApplied('external_record_link_collision');
        }

        $trustedLookup = $this->linkGuard->resolveTrustedVariantLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredState->productVariantId,
        );

        if ($trustedLookup->isAmbiguous()) {
            return $this->unknownOrAmbiguous('ambiguous_variant_identity_links');
        }

        if ($trustedLookup->isTrusted()) {
            $storedExternalSku = $trustedLookup->link->external_identifier;

            if ($storedExternalSku !== $desiredState->sku) {
                return $this->unknownOrAmbiguous(
                    'linked_identity_drift_requires_adobe_validation',
                    subjectSku: $storedExternalSku,
                );
            }

            $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);

            return $this->executeTrustedLinkUpdate(
                $input,
                $context,
                $desiredState,
                $storedExternalSku,
            );
        }

        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);

        return $this->executeNoLinkCreate($input, $context, $desiredState);
    }

    private function executeTrustedLinkUpdate(
        AdobeProductSimpleCommandInput $input,
        AdobePaaSRequestContext $context,
        AdobeProductDesiredState $desiredState,
        string $linkedExternalSku,
    ): AdobeProductSimpleCommandResult {
        $initialGet = $this->remoteStateClient->getProductWithContext($context, $linkedExternalSku);

        if ($initialGet->classification === AdobeProductRemoteGetClassification::TrustedKnownMissing) {
            return $this->knownNotApplied(
                'linked_remote_missing',
                subjectSku: $linkedExternalSku,
                remoteGetClassification: $initialGet->classification,
            );
        }

        if ($initialGet->classification !== AdobeProductRemoteGetClassification::Found
            || $initialGet->observedState === null
        ) {
            return $this->unknownOrAmbiguous(
                'linked_remote_get_untrusted',
                subjectSku: $linkedExternalSku,
                remoteGetClassification: $initialGet->classification,
            );
        }

        if ($this->comparator->controlledStateMatches($desiredState, $initialGet->observedState)) {
            return $this->knownApplied(
                'trusted_link_no_op',
                subjectSku: $linkedExternalSku,
                ownershipTrustSatisfied: true,
            );
        }

        if (! $this->permitsConsequentialWrite($input)) {
            return $this->knownNotApplied(
                'writer_lease_expired_before_consequential_write',
                subjectSku: $linkedExternalSku,
                remoteGetClassification: $initialGet->classification,
            );
        }

        [$putResult, $putTransportException] = $this->remoteStateClient->putProduct($context, $desiredState);

        if ($this->shouldReconcileAfterWrite($putResult, $putTransportException)) {
            return $this->reconcileAfterWrite(
                $input,
                $context,
                $desiredState,
                $linkedExternalSku,
                consequentialWriteAttempts: 1,
                reasonCode: 'trusted_link_put_inconclusive',
                allowLinkPersistence: false,
                requireOwnershipForApplied: true,
                ownershipTrustSatisfied: true,
            );
        }

        return $this->reconcileAfterWrite(
            $input,
            $context,
            $desiredState,
            $linkedExternalSku,
            consequentialWriteAttempts: 1,
            reasonCode: 'trusted_link_put_reconciled',
            allowLinkPersistence: false,
            requireOwnershipForApplied: true,
            ownershipTrustSatisfied: true,
        );
    }

    private function executeNoLinkCreate(
        AdobeProductSimpleCommandInput $input,
        AdobePaaSRequestContext $context,
        AdobeProductDesiredState $desiredState,
    ): AdobeProductSimpleCommandResult {
        $initialGet = $this->remoteStateClient->getProductWithContext($context, $desiredState->sku);

        if ($initialGet->classification === AdobeProductRemoteGetClassification::Found) {
            return $this->knownNotApplied(
                'remote_found_without_trusted_link',
                subjectSku: $desiredState->sku,
                remoteGetClassification: $initialGet->classification,
            );
        }

        if ($initialGet->classification !== AdobeProductRemoteGetClassification::TrustedKnownMissing) {
            return $this->unknownOrAmbiguous(
                'initial_get_untrusted',
                subjectSku: $desiredState->sku,
                remoteGetClassification: $initialGet->classification,
            );
        }

        if (! $this->permitsConsequentialWrite($input)) {
            return $this->knownNotApplied(
                'writer_lease_expired_before_consequential_write',
                subjectSku: $desiredState->sku,
                remoteGetClassification: $initialGet->classification,
            );
        }

        [$postResult, $postTransportException] = $this->remoteStateClient->postProduct($context, $desiredState);

        $ownershipEvidence = $this->buildCreateOwnershipEvidence(
            $initialGet->classification,
            $postResult,
            $postTransportException,
            $desiredState->sku,
        );

        if ($this->shouldReconcileAfterWrite($postResult, $postTransportException)) {
            return $this->reconcileAfterWrite(
                $input,
                $context,
                $desiredState,
                $desiredState->sku,
                consequentialWriteAttempts: 1,
                reasonCode: 'no_link_post_inconclusive',
                allowLinkPersistence: true,
                requireOwnershipForApplied: true,
                ownershipEvidence: $ownershipEvidence,
            );
        }

        if (! $this->responseBodyConfirmsSku($postResult, $desiredState->sku)) {
            return $this->reconcileAfterWrite(
                $input,
                $context,
                $desiredState,
                $desiredState->sku,
                consequentialWriteAttempts: 1,
                reasonCode: 'no_link_post_inconclusive_body',
                allowLinkPersistence: true,
                requireOwnershipForApplied: true,
                ownershipEvidence: $ownershipEvidence,
            );
        }

        return $this->reconcileAfterWrite(
            $input,
            $context,
            $desiredState,
            $desiredState->sku,
            consequentialWriteAttempts: 1,
            reasonCode: 'no_link_post_reconciled',
            allowLinkPersistence: true,
            requireOwnershipForApplied: true,
            ownershipEvidence: $ownershipEvidence,
        );
    }

    private function reconcileAfterWrite(
        AdobeProductSimpleCommandInput $input,
        AdobePaaSRequestContext $context,
        AdobeProductDesiredState $desiredState,
        string $sku,
        int $consequentialWriteAttempts,
        string $reasonCode,
        bool $allowLinkPersistence,
        bool $requireOwnershipForApplied,
        ?AdobeProductCreateOwnershipEvidence $ownershipEvidence = null,
        bool $ownershipTrustSatisfied = false,
    ): AdobeProductSimpleCommandResult {
        $reconciliationGet = $this->remoteStateClient->getProductWithContext($context, $sku);

        if ($reconciliationGet->classification !== AdobeProductRemoteGetClassification::Found
            || $reconciliationGet->observedState === null
        ) {
            return $this->unknownOrAmbiguous(
                $reasonCode.'_reconciliation_inconclusive',
                subjectSku: $sku,
                remoteGetClassification: $reconciliationGet->classification,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: 1,
            );
        }

        if (! $this->comparator->controlledStateMatches($desiredState, $reconciliationGet->observedState)) {
            return $this->unknownOrAmbiguous(
                $reasonCode.'_reconciliation_mismatch',
                subjectSku: $sku,
                remoteGetClassification: $reconciliationGet->classification,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: 1,
            );
        }

        $linkPersisted = false;
        $ownershipProven = $ownershipTrustSatisfied;

        if ($allowLinkPersistence
            && $ownershipEvidence !== null
            && $this->ownershipTrustPolicy->canPersistNewLink(
                $desiredState,
                $reconciliationGet->observedState,
                $ownershipEvidence,
            )
        ) {
            try {
                $this->linkPersister->persistTrustedVariantLink(
                    $input->workspaceId,
                    $input->connectorAccountId,
                    $desiredState,
                );
                $linkPersisted = true;
                $ownershipProven = true;
            } catch (AdobeProductExternalRecordLinkPersistenceException) {
                return $this->unknownOrAmbiguous(
                    $reasonCode.'_link_persistence_failed',
                    subjectSku: $sku,
                    remoteGetClassification: $reconciliationGet->classification,
                    consequentialWriteAttempts: $consequentialWriteAttempts,
                    reconciliationGetAttempts: 1,
                );
            }
        }

        if ($requireOwnershipForApplied && ! $ownershipProven) {
            return $this->unknownOrAmbiguous(
                $reasonCode.'_ownership_not_proven',
                subjectSku: $sku,
                remoteGetClassification: $reconciliationGet->classification,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: 1,
            );
        }

        return $this->knownApplied(
            $reasonCode,
            subjectSku: $sku,
            remoteGetClassification: $reconciliationGet->classification,
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
        if ($transportException !== null) {
            return true;
        }

        if ($httpResult === null) {
            return true;
        }

        return $httpResult->statusCode < 200 || $httpResult->statusCode >= 300;
    }

    private function buildCreateOwnershipEvidence(
        AdobeProductRemoteGetClassification $preWriteClassification,
        ?ConnectorHttpResult $httpResult,
        ?ConnectorTransportException $transportException,
        string $expectedSku,
    ): AdobeProductCreateOwnershipEvidence {
        if ($transportException !== null
            || $httpResult === null
            || $httpResult->statusCode < 200
            || $httpResult->statusCode >= 300
            || ! $this->responseBodyConfirmsSku($httpResult, $expectedSku)
        ) {
            return AdobeProductCreateOwnershipEvidence::inconclusive($preWriteClassification);
        }

        return AdobeProductCreateOwnershipEvidence::definitiveCreate($preWriteClassification);
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

        $product = $payload;

        if (isset($payload['product']) && is_array($payload['product'])) {
            $product = $payload['product'];
        }

        $sku = $product['sku'] ?? null;

        return is_string($sku) && $sku === $expectedSku;
    }

    private function permitsConsequentialWrite(AdobeProductSimpleCommandInput $input): bool
    {
        if ($input->consequentialWriteGate === null) {
            return true;
        }

        return $input->consequentialWriteGate->permitsConsequentialWrite();
    }

    private function knownNotApplied(
        string $reasonCode,
        ?string $subjectSku = null,
        ?AdobeProductRemoteGetClassification $remoteGetClassification = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeProductSimpleCommandResult {
        return new AdobeProductSimpleCommandResult(
            AdobeProductAppliedStateKnowledge::KnownNotApplied,
            new AdobeProductCommandSafeEvidence(
                reasonCode: $reasonCode,
                subjectSku: $subjectSku,
                remoteGetClassification: $remoteGetClassification,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: $reconciliationGetAttempts,
            ),
        );
    }

    private function knownApplied(
        string $reasonCode,
        ?string $subjectSku = null,
        ?AdobeProductRemoteGetClassification $remoteGetClassification = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        bool $ownershipTrustSatisfied = false,
        bool $externalRecordLinkPersisted = false,
    ): AdobeProductSimpleCommandResult {
        return new AdobeProductSimpleCommandResult(
            AdobeProductAppliedStateKnowledge::KnownApplied,
            new AdobeProductCommandSafeEvidence(
                reasonCode: $reasonCode,
                subjectSku: $subjectSku,
                remoteGetClassification: $remoteGetClassification,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: $reconciliationGetAttempts,
                externalRecordLinkPersisted: $externalRecordLinkPersisted,
                ownershipTrustSatisfied: $ownershipTrustSatisfied,
            ),
        );
    }

    private function unknownOrAmbiguous(
        string $reasonCode,
        ?string $subjectSku = null,
        ?AdobeProductRemoteGetClassification $remoteGetClassification = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        bool $ownershipTrustSatisfied = false,
    ): AdobeProductSimpleCommandResult {
        return new AdobeProductSimpleCommandResult(
            AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            new AdobeProductCommandSafeEvidence(
                reasonCode: $reasonCode,
                subjectSku: $subjectSku,
                remoteGetClassification: $remoteGetClassification,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: $reconciliationGetAttempts,
                ownershipTrustSatisfied: $ownershipTrustSatisfied,
            ),
        );
    }
}
