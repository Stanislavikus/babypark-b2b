<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\SyncLiveOutcome;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableProductCommandCoordinator;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableProductExecutionResult;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductCommandSafeEvidence;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkGuard;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandInput;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandResult;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductMediaLiveExecutor;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticFinding;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticOperation;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticPlanner;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;
use App\Support\Sync\Live\SyncLiveConnectorCapability;
use App\Support\Sync\Live\SyncLiveConsequentialWriteGate;
use App\Support\Sync\Live\SyncLiveFinding;
use App\Support\Sync\Live\SyncLiveProductExecutionResult;
use App\Support\Sync\Preview\ProductExecutionAggregate;

final class AdobeProductExportLiveCapability implements SyncLiveConnectorCapability
{
    private const string CONFIGURABLE_CLASSIFICATION_TRANSITION_REASON = 'configurable_classification_transition_requires_adobe_validation';

    private const string INACTIVE_ONLY_CONFIGURABLE_FAMILY_REASON = 'inactive_only_configurable_family_requires_adobe_validation';

    private const string AMBIGUOUS_PARENT_IDENTITY_REASON = 'ambiguous_configurable_parent_identity_links';

    public function __construct(
        private readonly AdobeProductExportRunMetadataPreparer $metadataPreparer,
        private readonly AdobeStoreConfigReader $storeConfigReader,
        private readonly AdobeProductExportSemanticPlanner $semanticPlanner,
        private readonly AdobeProductSimpleCommandExecutor $commandExecutor,
        private readonly AdobeConfigurableProductCommandCoordinator $configurableCoordinator,
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
        private readonly AdobeProductMediaLiveExecutor $mediaLiveExecutor,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function prepareRun(
        string $workspaceId,
        string $connectorAccountId,
        array $snapshot,
    ): AdobeProductExportLiveRunContext {
        $metadata = $this->metadataPreparer->prepareMetadata(
            $workspaceId,
            $connectorAccountId,
            $snapshot,
        );
        $baseCurrency = $this->storeConfigReader->readBaseCurrency($workspaceId, $connectorAccountId);

        return new AdobeProductExportLiveRunContext(
            workspaceId: $workspaceId,
            connectorAccountId: $connectorAccountId,
            metadata: $metadata,
            adobeBaseCurrency: $baseCurrency,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function executeProduct(
        ProductExecutionAggregate $aggregate,
        array $snapshot,
        object $runContext,
        SyncLiveConsequentialWriteGate $consequentialWriteGate,
    ): SyncLiveProductExecutionResult {
        if (! $runContext instanceof AdobeProductExportLiveRunContext) {
            throw new \InvalidArgumentException('Adobe product export live requires AdobeProductExportLiveRunContext run context.');
        }

        $semanticResult = $this->semanticPlanner->evaluate(
            $aggregate,
            $snapshot,
            $runContext->metadata,
        );

        $ambiguousParentResult = $this->resolveAmbiguousParentIdentityResult(
            $runContext->workspaceId,
            $runContext->connectorAccountId,
            (string) $aggregate->productId,
        );

        if ($ambiguousParentResult !== null) {
            return $ambiguousParentResult;
        }

        $classificationTransition = $this->resolveClassificationTransitionResult(
            $semanticResult,
            $runContext->workspaceId,
            $runContext->connectorAccountId,
            (string) $aggregate->productId,
        );

        if ($classificationTransition !== null) {
            return $classificationTransition;
        }

        if ($semanticResult->hasBlockingFindings()) {
            return $this->semanticNotApplied($semanticResult);
        }

        if ($this->isConfigurablePath($semanticResult)) {
            $configurableResult = $this->configurableCoordinator->execute(
                workspaceId: $runContext->workspaceId,
                connectorAccountId: $runContext->connectorAccountId,
                semanticResult: $semanticResult,
                adobeBaseCurrency: $runContext->adobeBaseCurrency,
                metadata: $runContext->metadata,
                consequentialWriteGate: $consequentialWriteGate,
            );

            $coreResult = $this->mapConfigurableResult($configurableResult, $semanticResult);

            return $this->mediaLiveExecutor->executeAfterCoreProduct(
                $aggregate,
                $semanticResult,
                $coreResult,
                $runContext,
                $consequentialWriteGate,
                isConfigurablePath: true,
            );
        }

        if (! $this->isStage3BSimplePath($semanticResult)) {
            return new SyncLiveProductExecutionResult(
                outcome: SyncLiveOutcome::NotApplied,
                findings: [
                    new SyncLiveFinding(
                        code: 'unsupported_semantic_product_shape',
                        subject: $aggregate->productId,
                    ),
                ],
            );
        }

        $commandResult = $this->commandExecutor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $runContext->workspaceId,
            connectorAccountId: $runContext->connectorAccountId,
            semanticResult: $semanticResult,
            adobeBaseCurrency: $runContext->adobeBaseCurrency,
            consequentialWriteGate: $consequentialWriteGate,
        ));

        $coreResult = $this->mapCommandResult($commandResult, $semanticResult);

        return $this->mediaLiveExecutor->executeAfterCoreProduct(
            $aggregate,
            $semanticResult,
            $coreResult,
            $runContext,
            $consequentialWriteGate,
            isConfigurablePath: false,
        );
    }

    private function isStage3BSimplePath(AdobeProductExportSemanticResult $semanticResult): bool
    {
        if ($semanticResult->operations === []) {
            return false;
        }

        $simpleOperations = array_values(array_filter(
            $semanticResult->operations,
            static fn (AdobeProductExportSemanticOperation $operation): bool => $operation->operation === 'simple_product',
        ));

        if (count($simpleOperations) !== 1) {
            return false;
        }

        foreach ($semanticResult->operations as $operation) {
            if ($operation->operation !== 'simple_product') {
                return false;
            }
        }

        return true;
    }

    private function isConfigurablePath(AdobeProductExportSemanticResult $semanticResult): bool
    {
        $hasConfigurableParent = false;
        $hasSimpleProduct = false;

        foreach ($semanticResult->operations as $operation) {
            if ($operation->operation === 'configurable_parent') {
                $hasConfigurableParent = true;
            }

            if ($operation->operation === 'simple_product') {
                $hasSimpleProduct = true;
            }
        }

        return $hasConfigurableParent && ! $hasSimpleProduct;
    }

    private function resolveAmbiguousParentIdentityResult(
        string $workspaceId,
        string $connectorAccountId,
        string $productId,
    ): ?SyncLiveProductExecutionResult {
        if (! ctype_digit($productId)) {
            return null;
        }

        $parentLookup = $this->linkGuard->resolveTrustedParentLinkBySubject(
            $workspaceId,
            $connectorAccountId,
            (int) $productId,
        );

        if (! $parentLookup->isAmbiguous()) {
            return null;
        }

        return new SyncLiveProductExecutionResult(
            outcome: SyncLiveOutcome::Ambiguous,
            findings: [
                new SyncLiveFinding(
                    code: self::AMBIGUOUS_PARENT_IDENTITY_REASON,
                    subject: $productId,
                ),
            ],
        );
    }

    private function resolveClassificationTransitionResult(
        AdobeProductExportSemanticResult $semanticResult,
        string $workspaceId,
        string $connectorAccountId,
        string $productId,
    ): ?SyncLiveProductExecutionResult {
        if (! ctype_digit($productId)) {
            return null;
        }

        $trustedParent = $this->linkGuard->resolveTrustedParentLinkBySubject(
            $workspaceId,
            $connectorAccountId,
            (int) $productId,
        );

        if (! $trustedParent->isTrusted()) {
            return null;
        }

        $hasConfigurableParent = $this->operationExists($semanticResult, 'configurable_parent');

        if (! $hasConfigurableParent) {
            return new SyncLiveProductExecutionResult(
                outcome: SyncLiveOutcome::NotApplied,
                findings: [
                    new SyncLiveFinding(
                        code: self::CONFIGURABLE_CLASSIFICATION_TRANSITION_REASON,
                        subject: $productId,
                    ),
                ],
            );
        }

        if ($semanticResult->hasBlockingFindings()) {
            return new SyncLiveProductExecutionResult(
                outcome: SyncLiveOutcome::NotApplied,
                findings: [
                    new SyncLiveFinding(
                        code: self::INACTIVE_ONLY_CONFIGURABLE_FAMILY_REASON,
                        subject: $productId,
                    ),
                ],
            );
        }

        return null;
    }

    private function operationExists(AdobeProductExportSemanticResult $semanticResult, string $operationType): bool
    {
        foreach ($semanticResult->operations as $operation) {
            if ($operation->operation === $operationType) {
                return true;
            }
        }

        return false;
    }

    private function mapConfigurableResult(
        AdobeConfigurableProductExecutionResult $configurableResult,
        AdobeProductExportSemanticResult $semanticResult,
    ): SyncLiveProductExecutionResult {
        $findings = [];

        foreach ($configurableResult->commandEvidence as $evidence) {
            $findings[] = new SyncLiveFinding(
                code: 'command_evidence',
                subject: $evidence->subjectSku ?? $evidence->variantId,
                context: [
                    'command_kind' => $evidence->commandKind,
                    'reason_code' => $evidence->reasonCode,
                    'applied_state_knowledge' => $evidence->appliedStateKnowledge->value,
                    'variant_id' => $evidence->variantId,
                    'attribute_id' => $evidence->attributeId,
                    'configurable_option_id' => $evidence->configurableOptionId,
                    'consequential_write_attempts' => $evidence->consequentialWriteAttempts,
                    'reconciliation_get_attempts' => $evidence->reconciliationGetAttempts,
                    'external_record_link_persisted' => $evidence->externalRecordLinkPersisted,
                    'ownership_trust_satisfied' => $evidence->ownershipTrustSatisfied,
                ],
            );
        }

        if ($semanticResult->findings !== [] && $configurableResult->outcome === SyncLiveOutcome::NotApplied) {
            foreach ($semanticResult->findings as $finding) {
                if (! $finding->isBlocking) {
                    $findings[] = new SyncLiveFinding(
                        code: $finding->code,
                        subject: $finding->subject !== '' ? $finding->subject : null,
                        context: $finding->context,
                    );
                }
            }
        }

        return new SyncLiveProductExecutionResult(
            outcome: $configurableResult->outcome,
            findings: $findings,
        );
    }

    private function semanticNotApplied(AdobeProductExportSemanticResult $semanticResult): SyncLiveProductExecutionResult
    {
        /** @var list<SyncLiveFinding> $findings */
        $findings = array_map(
            fn (AdobeProductExportSemanticFinding $finding): SyncLiveFinding => new SyncLiveFinding(
                code: $finding->code,
                subject: $finding->subject !== '' ? $finding->subject : null,
                context: $finding->context,
            ),
            $semanticResult->findings,
        );

        return new SyncLiveProductExecutionResult(
            outcome: SyncLiveOutcome::NotApplied,
            findings: $findings,
        );
    }

    private function mapCommandResult(
        AdobeProductSimpleCommandResult $commandResult,
        AdobeProductExportSemanticResult $semanticResult,
    ): SyncLiveProductExecutionResult {
        $outcome = match ($commandResult->appliedStateKnowledge) {
            AdobeProductAppliedStateKnowledge::KnownApplied => SyncLiveOutcome::Synchronized,
            AdobeProductAppliedStateKnowledge::KnownNotApplied => SyncLiveOutcome::NotApplied,
            AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous => SyncLiveOutcome::Ambiguous,
        };

        $findings = $this->commandEvidenceFindings($commandResult->evidence);

        if ($semanticResult->findings !== [] && $outcome === SyncLiveOutcome::NotApplied) {
            foreach ($semanticResult->findings as $finding) {
                if (! $finding->isBlocking) {
                    $findings[] = new SyncLiveFinding(
                        code: $finding->code,
                        subject: $finding->subject !== '' ? $finding->subject : null,
                        context: $finding->context,
                    );
                }
            }
        }

        return new SyncLiveProductExecutionResult(
            outcome: $outcome,
            findings: $findings,
        );
    }

    /**
     * @return list<SyncLiveFinding>
     */
    private function commandEvidenceFindings(AdobeProductCommandSafeEvidence $evidence): array
    {
        $context = [
            'reason_code' => $evidence->reasonCode,
            'consequential_write_attempts' => $evidence->consequentialWriteAttempts,
            'reconciliation_get_attempts' => $evidence->reconciliationGetAttempts,
            'external_record_link_persisted' => $evidence->externalRecordLinkPersisted,
            'ownership_trust_satisfied' => $evidence->ownershipTrustSatisfied,
        ];

        if ($evidence->subjectSku !== null) {
            $context['subject_sku'] = $evidence->subjectSku;
        }

        if ($evidence->remoteGetClassification !== null) {
            $context['remote_get_classification'] = $evidence->remoteGetClassification->value;
        }

        return [
            new SyncLiveFinding(
                code: 'command_evidence',
                subject: $evidence->subjectSku,
                context: $context,
            ),
        ];
    }
}
