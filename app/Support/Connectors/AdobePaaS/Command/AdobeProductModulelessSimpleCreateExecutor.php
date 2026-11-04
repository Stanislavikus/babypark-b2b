<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\ConnectorAccountOperationLock;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorTransportException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class AdobeProductModulelessSimpleCreateExecutor
{
    public function __construct(
        private readonly AdobeProductDesiredStateCompiler $compiler,
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
        private readonly AdobeProductRemoteStateComparator $comparator,
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
        private readonly AdobeProductCreateAttributeValidator $attributeValidator,
        private readonly AdobeProductPlatformCreatedLinkPersister $linkPersister,
    ) {}

    public function execute(AdobeProductSimpleCommandInput $input): AdobeProductSimpleCommandResult
    {
        if ($input->semanticResult->hasBlockingFindings()) {
            return $this->knownNotApplied('blocking_semantic_findings');
        }

        try {
            $desiredState = $this->compiler->compileFromSemanticResult($input->semanticResult);
        } catch (AdobeProductCommandCompilationException) {
            return $this->knownNotApplied('semantic_compilation_failed');
        }

        try {
            return Cache::lock(
                ConnectorAccountOperationLock::cacheKey($input->connectorAccountId),
                $this->operationLockSeconds(),
            )->block(5, fn (): AdobeProductSimpleCommandResult => $this->executeDesiredState($input, $desiredState));
        } catch (LockTimeoutException) {
            return $this->unknownOrAmbiguous('product_create_account_lock_timeout', $desiredState->sku);
        }
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

    public function preflightSimpleChild(
        AdobeProductSimpleCommandInput $input,
        string $variantId,
    ): ?AdobeProductSimpleCommandResult {
        try {
            $desired = $this->compiler->compileSimpleChildFromSemanticResult($input->semanticResult, $variantId);
        } catch (AdobeProductCommandCompilationException) {
            return $this->knownNotApplied('semantic_compilation_failed');
        }

        if ($input->adobeBaseCurrency === null || $input->adobeBaseCurrency !== $desired->priceCurrency) {
            return $this->knownNotApplied('currency_mismatch', $desired->sku);
        }

        if ($this->linkGuard->hasCrossSubjectCollision(
            $input->workspaceId,
            $input->connectorAccountId,
            $desired->sku,
            $desired->productVariantId,
        ) || $this->linkGuard->hasAnyVariantLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $desired->productVariantId,
        )) {
            return $this->knownNotApplied('external_record_link_collision', $desired->sku);
        }

        $validation = $this->attributeValidator->validate(
            $input->workspaceId,
            $input->connectorAccountId,
            $desired,
            'simple',
        );
        if (! $validation->ready) {
            return $this->knownNotApplied($validation->reasonCode, $desired->sku);
        }

        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
        $read = $this->remoteStateClient->getProductWithContext($context, $desired->sku);

        return match ($read->classification) {
            AdobeProductRemoteGetClassification::TrustedKnownMissing => null,
            AdobeProductRemoteGetClassification::Found => $this->knownNotApplied('remote_found_without_trusted_link', $desired->sku),
            default => $this->unknownOrAmbiguous('initial_get_untrusted', $desired->sku),
        };
    }

    private function executeDesiredState(
        AdobeProductSimpleCommandInput $input,
        AdobeProductDesiredState $desiredState,
    ): AdobeProductSimpleCommandResult {
        if ($input->adobeBaseCurrency === null || $input->adobeBaseCurrency === '') {
            return $this->knownNotApplied('currency_evidence_missing', $desiredState->sku);
        }

        if ($input->adobeBaseCurrency !== $desiredState->priceCurrency) {
            return $this->knownNotApplied('currency_mismatch', $desiredState->sku);
        }

        if ($this->linkGuard->hasCrossSubjectCollision(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredState->sku,
            $desiredState->productVariantId,
        )) {
            return $this->knownNotApplied('external_record_link_collision', $desiredState->sku);
        }

        $trustedLookup = $this->linkGuard->resolveTrustedVariantLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredState->productVariantId,
        );

        if ($trustedLookup->isAmbiguous()) {
            return $this->unknownOrAmbiguous('ambiguous_variant_identity_links', $desiredState->sku);
        }

        if ($trustedLookup->isTrusted()) {
            return $this->knownNotApplied('trusted_link_already_exists', $desiredState->sku);
        }

        if ($this->linkGuard->hasAnyVariantLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredState->productVariantId,
        )) {
            return $this->knownNotApplied('untrusted_link_requires_entity_trust', $desiredState->sku);
        }

        $validation = $this->attributeValidator->validate(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredState,
            'simple',
        );

        if (! $validation->ready) {
            return $this->knownNotApplied(
                $validation->reasonCode,
                $desiredState->sku,
                warningCodes: array_map(
                    static fn (string $code): string => 'attribute:'.$code,
                    $validation->attributeCodes,
                ),
            );
        }

        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
        $initialGet = $this->remoteStateClient->getProductWithContext($context, $desiredState->sku);

        if ($initialGet->classification === AdobeProductRemoteGetClassification::Found) {
            return $this->knownNotApplied(
                'remote_found_without_trusted_link',
                $desiredState->sku,
                $initialGet->classification,
            );
        }

        if ($initialGet->classification !== AdobeProductRemoteGetClassification::TrustedKnownMissing) {
            return $this->unknownOrAmbiguous(
                'initial_get_untrusted',
                $desiredState->sku,
                $initialGet->classification,
            );
        }

        if (
            $input->consequentialWriteGate === null
            || ! $input->consequentialWriteGate->permitsConsequentialWrite()
            || ! $input->consequentialWriteGate->permitsProductExecution()
        ) {
            return $this->knownNotApplied(
                'consequential_write_gate_closed',
                $desiredState->sku,
                $initialGet->classification,
            );
        }

        [$postResult, $postTransportException] = $this->remoteStateClient->postProduct($context, $desiredState);

        if ($this->isAmbiguousWrite($postResult, $postTransportException)) {
            return $this->reconcileAmbiguousPost($input, $desiredState, $context);
        }

        if (! $this->isSuccessfulWriteResponse($postResult)) {
            $reconciliation = $this->remoteStateClient->getProductWithContext($context, $desiredState->sku);

            if ($reconciliation->classification === AdobeProductRemoteGetClassification::TrustedKnownMissing) {
                return $this->knownNotApplied(
                    'adobe_create_post_rejected',
                    $desiredState->sku,
                    $reconciliation->classification,
                    consequentialWriteAttempts: 1,
                    reconciliationGetAttempts: 1,
                );
            }

            return $this->unknownOrAmbiguous(
                'adobe_create_post_rejected_remote_state_uncertain',
                $desiredState->sku,
                $reconciliation->classification,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
            );
        }

        $postIdentity = $this->parseSuccessfulPostIdentity($postResult, $desiredState->sku);

        if ($postIdentity === null) {
            return $this->unknownOrAmbiguous(
                'adobe_create_post_success_identity_inconclusive',
                $desiredState->sku,
                consequentialWriteAttempts: 1,
            );
        }

        [$postEntityId, $postSku] = $postIdentity;
        $reconciliation = $this->remoteStateClient->getProductWithContext($context, $postSku);

        if (
            $reconciliation->classification !== AdobeProductRemoteGetClassification::Found
            || $reconciliation->observedState === null
        ) {
            return $this->unknownOrAmbiguous(
                'adobe_create_reconciliation_inconclusive',
                $desiredState->sku,
                $reconciliation->classification,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
            );
        }

        if ($reconciliation->observedState->entityId !== $postEntityId) {
            return $this->unknownOrAmbiguous(
                'adobe_create_entity_id_mismatch',
                $desiredState->sku,
                $reconciliation->classification,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
            );
        }

        if (! $this->comparator->controlledStateMatches($desiredState, $reconciliation->observedState)) {
            return $this->unknownOrAmbiguous(
                'adobe_create_reconciliation_mismatch',
                $desiredState->sku,
                $reconciliation->classification,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
            );
        }

        try {
            $this->linkPersister->persistVariantLink(
                $input->workspaceId,
                $input->connectorAccountId,
                $desiredState->productVariantId,
                $desiredState->sku,
                $postEntityId,
            );
        } catch (AdobeProductPlatformCreatedLinkPersistenceException $exception) {
            return $this->unknownOrAmbiguous(
                'adobe_create_link_persistence_failed',
                $desiredState->sku,
                $reconciliation->classification,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
                ownershipTrustSatisfied: true,
                warningCodes: [$exception->reasonCode],
            );
        }

        return $this->knownApplied(
            'adobe_create_post_reconciled',
            $desiredState->sku,
            $reconciliation->classification,
            consequentialWriteAttempts: 1,
            reconciliationGetAttempts: 1,
            externalRecordLinkPersisted: true,
            ownershipTrustSatisfied: true,
        );
    }

    private function reconcileAmbiguousPost(
        AdobeProductSimpleCommandInput $input,
        AdobeProductDesiredState $desiredState,
        AdobePaaSRequestContext $context,
    ): AdobeProductSimpleCommandResult {
        $reconciliation = $this->remoteStateClient->getProductWithContext($context, $desiredState->sku);

        return $this->unknownOrAmbiguous(
            $reconciliation->classification === AdobeProductRemoteGetClassification::Found
                ? 'adobe_create_post_ambiguous_remote_present'
                : 'adobe_create_post_ambiguous',
            $desiredState->sku,
            $reconciliation->classification,
            consequentialWriteAttempts: 1,
            reconciliationGetAttempts: 1,
        );
    }

    /** @return array{0:int,1:string}|null */
    private function parseSuccessfulPostIdentity(?ConnectorHttpResult $result, string $expectedSku): ?array
    {
        if ($result === null) {
            return null;
        }

        $payload = json_decode($result->body, true);

        if (! is_array($payload)) {
            return null;
        }

        if (isset($payload['product']) && is_array($payload['product'])) {
            $payload = $payload['product'];
        }

        $id = $payload['id'] ?? null;
        $sku = $payload['sku'] ?? null;

        if ((! is_int($id) && ! (is_string($id) && ctype_digit($id))) || ! is_string($sku)) {
            return null;
        }

        $entityId = (int) $id;

        if ($entityId <= 0 || $sku !== $expectedSku) {
            return null;
        }

        return [$entityId, $sku];
    }

    private function isAmbiguousWrite(
        ?ConnectorHttpResult $result,
        ?ConnectorTransportException $exception,
    ): bool {
        return $exception !== null
            || $result === null
            || $result->statusCode >= 500
            || $result->statusCode === 429;
    }

    private function isSuccessfulWriteResponse(?ConnectorHttpResult $result): bool
    {
        return $result !== null && $result->statusCode >= 200 && $result->statusCode < 300;
    }

    private function operationLockSeconds(): int
    {
        return max(
            180,
            (int) config('sync_runtime.live_job_timeout_seconds')
                + (int) config('sync_runtime.max_inflight_external_request_seconds'),
        );
    }

    private function knownApplied(
        string $reasonCode,
        ?string $subjectSku = null,
        ?AdobeProductRemoteGetClassification $remoteGetClassification = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        bool $externalRecordLinkPersisted = false,
        bool $ownershipTrustSatisfied = false,
        array $warningCodes = [],
    ): AdobeProductSimpleCommandResult {
        return $this->result(
            AdobeProductAppliedStateKnowledge::KnownApplied,
            $reasonCode,
            $subjectSku,
            $remoteGetClassification,
            $consequentialWriteAttempts,
            $reconciliationGetAttempts,
            $externalRecordLinkPersisted,
            $ownershipTrustSatisfied,
            $warningCodes,
        );
    }

    private function knownNotApplied(
        string $reasonCode,
        ?string $subjectSku = null,
        ?AdobeProductRemoteGetClassification $remoteGetClassification = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        array $warningCodes = [],
    ): AdobeProductSimpleCommandResult {
        return $this->result(
            AdobeProductAppliedStateKnowledge::KnownNotApplied,
            $reasonCode,
            $subjectSku,
            $remoteGetClassification,
            $consequentialWriteAttempts,
            $reconciliationGetAttempts,
            false,
            false,
            $warningCodes,
        );
    }

    private function unknownOrAmbiguous(
        string $reasonCode,
        ?string $subjectSku = null,
        ?AdobeProductRemoteGetClassification $remoteGetClassification = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        bool $ownershipTrustSatisfied = false,
        array $warningCodes = [],
    ): AdobeProductSimpleCommandResult {
        return $this->result(
            AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            $reasonCode,
            $subjectSku,
            $remoteGetClassification,
            $consequentialWriteAttempts,
            $reconciliationGetAttempts,
            false,
            $ownershipTrustSatisfied,
            $warningCodes,
        );
    }

    private function result(
        AdobeProductAppliedStateKnowledge $knowledge,
        string $reasonCode,
        ?string $subjectSku,
        ?AdobeProductRemoteGetClassification $remoteGetClassification,
        int $consequentialWriteAttempts,
        int $reconciliationGetAttempts,
        bool $externalRecordLinkPersisted,
        bool $ownershipTrustSatisfied,
        array $warningCodes,
    ): AdobeProductSimpleCommandResult {
        return new AdobeProductSimpleCommandResult(
            $knowledge,
            new AdobeProductCommandSafeEvidence(
                reasonCode: $reasonCode,
                subjectSku: $subjectSku,
                remoteGetClassification: $remoteGetClassification,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: $reconciliationGetAttempts,
                externalRecordLinkPersisted: $externalRecordLinkPersisted,
                ownershipTrustSatisfied: $ownershipTrustSatisfied,
                warningCodes: $warningCodes,
            ),
        );
    }
}
