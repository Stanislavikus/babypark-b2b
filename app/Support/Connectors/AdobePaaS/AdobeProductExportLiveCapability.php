<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\SyncLiveOutcome;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductCommandSafeEvidence;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandInput;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandResult;
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
    private const string CONFIGURABLE_LIVE_REASON = 'configurable_live_requires_stage_3c';

    public function __construct(
        private readonly AdobeProductExportRunMetadataPreparer $metadataPreparer,
        private readonly AdobeStoreConfigReader $storeConfigReader,
        private readonly AdobeProductExportSemanticPlanner $semanticPlanner,
        private readonly AdobeProductSimpleCommandExecutor $commandExecutor,
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

        if ($semanticResult->hasBlockingFindings()) {
            return $this->semanticNotApplied($semanticResult);
        }

        if (! $this->isStage3BSimplePath($semanticResult)) {
            return new SyncLiveProductExecutionResult(
                outcome: SyncLiveOutcome::NotApplied,
                findings: [
                    new SyncLiveFinding(
                        code: self::CONFIGURABLE_LIVE_REASON,
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

        return $this->mapCommandResult($commandResult, $semanticResult);
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
