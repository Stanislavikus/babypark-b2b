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
        private readonly ?AdobeSafeSyncClient $safeSyncClient = null,
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

        if (! $trustedLookup->isTrusted()) {
            return $this->knownNotApplied('link_required', subjectSku: $desiredState->sku);
        }

        $trustedLink = $trustedLookup->link;

        if ($trustedLink === null) {
            return $this->unknownOrAmbiguous('trusted_variant_identity_link_missing');
        }

        if ($trustedLink->external_identifier !== $desiredState->sku) {
            return $this->knownNotApplied(
                'trusted_link_sku_mismatch',
                subjectSku: $desiredState->sku,
            );
        }

        $logicalEntityId = $this->logicalEntityId($trustedLink->external_record_discriminator);

        if ($logicalEntityId === null) {
            return $this->knownNotApplied(
                'trusted_link_discriminator_invalid',
                subjectSku: $desiredState->sku,
            );
        }

        if (
            $input->consequentialWriteGate === null
            || ! $input->consequentialWriteGate->permitsProductExecution()
            || ! $input->consequentialWriteGate->permitsConsequentialWrite()
        ) {
            return $this->knownNotApplied(
                'consequential_write_gate_closed',
                subjectSku: $desiredState->sku,
            );
        }

        if ($this->safeSyncClient === null) {
            return $this->knownNotApplied(
                'entity_bound_mutation_bridge_unavailable',
                subjectSku: $desiredState->sku,
            );
        }

        $mappedAttributes = $this->safeSyncMappedAttributes($desiredState->customAttributes);

        if ($mappedAttributes === null) {
            return $this->knownNotApplied(
                'safe_sync_mapped_attribute_value_unsupported',
                subjectSku: $desiredState->sku,
            );
        }

        $writeResult = $this->safeSyncClient->writeSimpleProduct(
            $input->workspaceId,
            $input->connectorAccountId,
            $logicalEntityId,
            new AdobeSafeSyncSimpleProductWriteRequest(
                expectedSku: $desiredState->sku,
                name: $desiredState->name,
                status: $desiredState->status,
                visibility: $desiredState->visibility,
                price: $desiredState->price,
                mappedAttributes: $mappedAttributes,
            ),
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

    private function logicalEntityId(mixed $discriminator): ?int
    {
        if (! is_string($discriminator) || $discriminator === '' || ! ctype_digit($discriminator)) {
            return null;
        }

        $logicalEntityId = (int) $discriminator;

        return $logicalEntityId > 0 ? $logicalEntityId : null;
    }

    /**
     * @param  array<string, mixed>  $customAttributes
     * @return list<AdobeSafeSyncSimpleProductWriteCustomAttribute>|null
     */
    private function safeSyncMappedAttributes(array $customAttributes): ?array
    {
        $mappedAttributes = [];

        foreach ($customAttributes as $attributeCode => $value) {
            if (! is_string($attributeCode) || $attributeCode === '') {
                return null;
            }

            $normalizedValue = $this->safeSyncScalarValue($value);

            if ($normalizedValue === null) {
                return null;
            }

            $mappedAttributes[] = new AdobeSafeSyncSimpleProductWriteCustomAttribute(
                $attributeCode,
                $normalizedValue,
            );
        }

        return $mappedAttributes;
    }

    private function safeSyncScalarValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return null;
    }

    private function knownNotApplied(
        string $reasonCode,
        ?string $subjectSku = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeProductSimpleCommandResult {
        return new AdobeProductSimpleCommandResult(
            AdobeProductAppliedStateKnowledge::KnownNotApplied,
            new AdobeProductCommandSafeEvidence(
                reasonCode: $reasonCode,
                subjectSku: $subjectSku,
                remoteGetClassification: null,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: $reconciliationGetAttempts,
            ),
        );
    }

    private function unknownOrAmbiguous(
        string $reasonCode,
        ?string $subjectSku = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeProductSimpleCommandResult {
        return new AdobeProductSimpleCommandResult(
            AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            new AdobeProductCommandSafeEvidence(
                reasonCode: $reasonCode,
                subjectSku: $subjectSku,
                remoteGetClassification: null,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: $reconciliationGetAttempts,
            ),
        );
    }
}
