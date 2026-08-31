<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncClient;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncSimpleProductWriteCustomAttribute;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncSimpleProductWriteRequest;

final class AdobeProductSimpleCommandExecutor
{
    public function __construct(
        private readonly AdobeProductDesiredStateCompiler $compiler,
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
        private readonly AdobeSafeSyncClient $safeSyncClient,
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

        return $this->executeDesiredState($input, $desiredState, consumeTrustedSafeSyncWrite: true);
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

        return $this->executeDesiredState($input, $desiredState, consumeTrustedSafeSyncWrite: false);
    }

    private function executeDesiredState(
        AdobeProductSimpleCommandInput $input,
        AdobeProductDesiredState $desiredState,
        bool $consumeTrustedSafeSyncWrite,
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

        if (! $trustedLookup->isTrusted() || $trustedLookup->link === null) {
            return $this->knownNotApplied('link_required', subjectSku: $desiredState->sku);
        }

        $trustedSku = $trustedLookup->link->external_identifier;

        if ($trustedSku !== $desiredState->sku) {
            return $this->knownNotApplied(
                'trusted_link_sku_mismatch',
                subjectSku: $trustedSku,
                ownershipTrustSatisfied: true,
            );
        }

        if (! $consumeTrustedSafeSyncWrite) {
            return $this->knownNotApplied(
                'entity_bound_mutation_bridge_required',
                subjectSku: $trustedSku,
                ownershipTrustSatisfied: true,
            );
        }

        $logicalEntityId = $this->parseLogicalEntityId((string) $trustedLookup->link->external_record_discriminator);

        if ($logicalEntityId === null) {
            return $this->knownNotApplied(
                'trusted_link_discriminator_invalid',
                subjectSku: $trustedSku,
                ownershipTrustSatisfied: true,
            );
        }

        if (
            $input->consequentialWriteGate === null
            || ! $input->consequentialWriteGate->permitsConsequentialWrite()
            || ! $input->consequentialWriteGate->permitsProductExecution()
        ) {
            return $this->knownNotApplied(
                'consequential_write_gate_closed',
                subjectSku: $trustedSku,
                ownershipTrustSatisfied: true,
            );
        }

        $writeResult = $this->safeSyncClient->writeSimpleProduct(
            $input->workspaceId,
            $input->connectorAccountId,
            $logicalEntityId,
            $this->buildSafeSyncWriteRequest($desiredState),
        );

        return new AdobeProductSimpleCommandResult(
            $writeResult->appliedStateKnowledge,
            new AdobeProductCommandSafeEvidence(
                reasonCode: $writeResult->reasonCode,
                subjectSku: $writeResult->sku,
                remoteGetClassification: null,
                consequentialWriteAttempts: $writeResult->consequentialWriteAttempts,
                reconciliationGetAttempts: 0,
                externalRecordLinkPersisted: false,
                ownershipTrustSatisfied: true,
                warningCodes: $writeResult->warningCodes,
            ),
        );
    }

    private function knownNotApplied(
        string $reasonCode,
        ?string $subjectSku = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        bool $ownershipTrustSatisfied = false,
        array $warningCodes = [],
    ): AdobeProductSimpleCommandResult {
        return new AdobeProductSimpleCommandResult(
            AdobeProductAppliedStateKnowledge::KnownNotApplied,
            new AdobeProductCommandSafeEvidence(
                reasonCode: $reasonCode,
                subjectSku: $subjectSku,
                remoteGetClassification: null,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: $reconciliationGetAttempts,
                externalRecordLinkPersisted: false,
                ownershipTrustSatisfied: $ownershipTrustSatisfied,
                warningCodes: $warningCodes,
            ),
        );
    }

    private function unknownOrAmbiguous(
        string $reasonCode,
        ?string $subjectSku = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        bool $ownershipTrustSatisfied = false,
        array $warningCodes = [],
    ): AdobeProductSimpleCommandResult {
        return new AdobeProductSimpleCommandResult(
            AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            new AdobeProductCommandSafeEvidence(
                reasonCode: $reasonCode,
                subjectSku: $subjectSku,
                remoteGetClassification: null,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: $reconciliationGetAttempts,
                externalRecordLinkPersisted: false,
                ownershipTrustSatisfied: $ownershipTrustSatisfied,
                warningCodes: $warningCodes,
            ),
        );
    }

    private function parseLogicalEntityId(string $externalRecordDiscriminator): ?int
    {
        if (preg_match('/^[1-9][0-9]*$/', $externalRecordDiscriminator) !== 1) {
            return null;
        }

        $logicalEntityId = (int) $externalRecordDiscriminator;

        if ((string) $logicalEntityId !== $externalRecordDiscriminator) {
            return null;
        }

        return $logicalEntityId;
    }

    private function buildSafeSyncWriteRequest(
        AdobeProductDesiredState $desiredState,
    ): AdobeSafeSyncSimpleProductWriteRequest {
        return new AdobeSafeSyncSimpleProductWriteRequest(
            expectedSku: $desiredState->sku,
            name: $desiredState->name,
            status: $desiredState->status,
            visibility: $desiredState->visibility,
            price: $desiredState->price,
            mappedAttributes: $this->buildSafeSyncMappedAttributes($desiredState->customAttributes),
        );
    }

    /**
     * @param  array<string, mixed>  $customAttributes
     * @return list<AdobeSafeSyncSimpleProductWriteCustomAttribute>
     */
    private function buildSafeSyncMappedAttributes(array $customAttributes): array
    {
        $mappedAttributes = [];

        foreach ($customAttributes as $attributeCode => $value) {
            if (! is_string($attributeCode) || $attributeCode === '') {
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $mappedAttributes[] = new AdobeSafeSyncSimpleProductWriteCustomAttribute(
                $attributeCode,
                $this->normalizeMappedAttributeValue($value),
            );
        }

        return $mappedAttributes;
    }

    private function normalizeMappedAttributeValue(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
