<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\SyncPreviewOutcome;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticFinding;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticOperation;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticPlanner;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;
use App\Support\Sync\Preview\ProductExecutionAggregate;
use App\Support\Sync\Preview\SyncPreviewFinding;
use App\Support\Sync\Preview\SyncPreviewPlanResult;

final class AdobeProductExportPreviewPlanner
{
    public function __construct(
        private readonly AdobeProductExportSemanticPlanner $semanticPlanner = new AdobeProductExportSemanticPlanner,
        private readonly AdobeProductExportSemanticFindingTranslator $findingTranslator = new AdobeProductExportSemanticFindingTranslator,
    ) {}

    /**
     * @param  array<string, mixed>  $configurationSnapshot
     */
    public function plan(
        ProductExecutionAggregate $aggregate,
        array $configurationSnapshot,
        ?AdobeProductExportExecutionMetadata $metadata = null,
    ): SyncPreviewPlanResult {
        $semanticResult = $this->semanticPlanner->evaluate($aggregate, $configurationSnapshot, $metadata);

        /** @var list<SyncPreviewFinding> $findings */
        $findings = array_map(
            fn (AdobeProductExportSemanticFinding $finding): SyncPreviewFinding => $this->findingTranslator->translate($finding),
            $semanticResult->findings,
        );

        $connectorPlan = $semanticResult->operations !== []
            ? $this->mapToPreviewPlan($semanticResult)
            : null;

        return new SyncPreviewPlanResult(
            outcome: $this->resolveOutcome($semanticResult->findings),
            findings: $findings,
            connectorPlan: $connectorPlan,
        );
    }

    private function mapToPreviewPlan(AdobeProductExportSemanticResult $semanticResult): AdobeProductExportPreviewPlan
    {
        $operations = array_map(
            static fn (AdobeProductExportSemanticOperation $operation): AdobeProductExportPreviewPlanOperation => new AdobeProductExportPreviewPlanOperation(
                operation: $operation->operation,
                context: $operation->context,
            ),
            $semanticResult->operations,
        );

        return new AdobeProductExportPreviewPlan($operations);
    }

    /**
     * @param  list<AdobeProductExportSemanticFinding>  $semanticFindings
     */
    private function resolveOutcome(array $semanticFindings): SyncPreviewOutcome
    {
        foreach ($semanticFindings as $finding) {
            if ($finding->isBlocking) {
                return SyncPreviewOutcome::Blocked;
            }
        }

        if ($semanticFindings !== []) {
            return SyncPreviewOutcome::Warning;
        }

        return SyncPreviewOutcome::Ready;
    }
}
