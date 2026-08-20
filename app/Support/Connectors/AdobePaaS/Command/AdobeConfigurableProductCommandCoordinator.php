<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;
use App\Support\Sync\Live\SyncLiveConsequentialWriteGate;

final class AdobeConfigurableProductCommandCoordinator
{
    public function __construct(
        private readonly AdobeConfigurableDesiredStateCompiler $desiredStateCompiler,
        private readonly AdobeProductSimpleCommandExecutor $simpleChildExecutor,
        private readonly AdobeConfigurableParentCommandExecutor $parentExecutor,
        private readonly AdobeConfigurableOptionCommandExecutor $optionExecutor,
        private readonly AdobeConfigurableChildLinkCommandExecutor $childLinkExecutor,
        private readonly AdobeConfigurableInactiveLinkedVariantLifecycleExecutor $inactiveLifecycleExecutor,
        private readonly AdobeConfigurableAppliedStateAggregator $aggregator,
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
    ) {}

    public function execute(
        string $workspaceId,
        string $connectorAccountId,
        AdobeProductExportSemanticResult $semanticResult,
        ?string $adobeBaseCurrency,
        ?AdobeProductExportExecutionMetadata $metadata,
        ?SyncLiveConsequentialWriteGate $consequentialWriteGate,
    ): AdobeConfigurableProductExecutionResult {
        $classificationTransition = $this->resolveClassificationTransitionEvidence(
            $workspaceId,
            $connectorAccountId,
            $semanticResult,
        );

        if ($classificationTransition !== null) {
            return new AdobeConfigurableProductExecutionResult(
                outcome: $this->aggregator->aggregate([$classificationTransition]),
                commandEvidence: [$classificationTransition],
            );
        }

        try {
            $desiredState = $this->desiredStateCompiler->compile($semanticResult, $workspaceId, $metadata);
        } catch (AdobeProductCommandCompilationException) {
            $evidence = new AdobeConfigurableCommandEvidence(
                commandKind: 'configurable_compile',
                appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                reasonCode: 'semantic_compilation_failed',
            );

            return new AdobeConfigurableProductExecutionResult(
                outcome: $this->aggregator->aggregate([$evidence]),
                commandEvidence: [$evidence],
            );
        }

        $input = new AdobeConfigurableCommandInput(
            workspaceId: $workspaceId,
            connectorAccountId: $connectorAccountId,
            semanticResult: $semanticResult,
            desiredState: $desiredState,
            adobeBaseCurrency: $adobeBaseCurrency,
            metadata: $metadata,
            consequentialWriteGate: $consequentialWriteGate,
        );

        /** @var list<AdobeConfigurableCommandEvidence> $evidence */
        $evidence = [];

        $stopWrites = false;

        foreach ($desiredState->activeChildVariantIds as $variantId) {
            $simpleInput = new AdobeProductSimpleCommandInput(
                workspaceId: $workspaceId,
                connectorAccountId: $connectorAccountId,
                semanticResult: $semanticResult,
                adobeBaseCurrency: $adobeBaseCurrency,
                consequentialWriteGate: $consequentialWriteGate,
            );

            $childResult = $this->simpleChildExecutor->executeSimpleChild($simpleInput, $variantId);
            $childEvidence = $this->mapSimpleChildEvidence($childResult, $variantId);
            $evidence[] = $childEvidence;

            if ($childEvidence->appliedStateKnowledge === AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous) {
                $stopWrites = true;

                break;
            }
        }

        if ($stopWrites) {
            return new AdobeConfigurableProductExecutionResult(
                outcome: $this->aggregator->aggregate($evidence),
                commandEvidence: $evidence,
            );
        }

        if (! $this->allChildrenKnownApplied($evidence, $desiredState->activeChildVariantIds)) {
            return new AdobeConfigurableProductExecutionResult(
                outcome: $this->aggregator->aggregate($evidence),
                commandEvidence: $evidence,
            );
        }

        $parentEvidence = $this->parentExecutor->execute($input);
        $evidence[] = $parentEvidence;

        if ($parentEvidence->appliedStateKnowledge === AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous) {
            return new AdobeConfigurableProductExecutionResult(
                outcome: $this->aggregator->aggregate($evidence),
                commandEvidence: $evidence,
            );
        }

        if ($parentEvidence->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied) {
            return new AdobeConfigurableProductExecutionResult(
                outcome: $this->aggregator->aggregate($evidence),
                commandEvidence: $evidence,
            );
        }

        $optionsKnownApplied = true;

        foreach ($desiredState->options as $desiredOption) {
            $optionEvidence = $this->optionExecutor->execute($input, $desiredOption);
            $evidence[] = $optionEvidence;

            if ($optionEvidence->appliedStateKnowledge === AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous) {
                return new AdobeConfigurableProductExecutionResult(
                    outcome: $this->aggregator->aggregate($evidence),
                    commandEvidence: $evidence,
                );
            }

            if ($optionEvidence->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied) {
                $optionsKnownApplied = false;
            }
        }

        if (! $optionsKnownApplied) {
            return new AdobeConfigurableProductExecutionResult(
                outcome: $this->aggregator->aggregate($evidence),
                commandEvidence: $evidence,
            );
        }

        foreach ($desiredState->childLinks as $desiredLink) {
            $linkEvidence = $this->childLinkExecutor->execute($input, $desiredLink);
            $evidence[] = $linkEvidence;

            if ($linkEvidence->appliedStateKnowledge === AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous) {
                return new AdobeConfigurableProductExecutionResult(
                    outcome: $this->aggregator->aggregate($evidence),
                    commandEvidence: $evidence,
                );
            }
        }

        if ($this->allChildLinksKnownApplied($evidence, $desiredState->childLinks)) {
            $lifecycleEvidence = $this->inactiveLifecycleExecutor->execute($input);
            $evidence = array_merge($evidence, $lifecycleEvidence);
        }

        return new AdobeConfigurableProductExecutionResult(
            outcome: $this->aggregator->aggregate($evidence),
            commandEvidence: $evidence,
        );
    }

    private function resolveClassificationTransitionEvidence(
        string $workspaceId,
        string $connectorAccountId,
        AdobeProductExportSemanticResult $semanticResult,
    ): ?AdobeConfigurableCommandEvidence {
        $hasConfigurableParentOperation = $this->hasOperationType($semanticResult, 'configurable_parent');
        $productId = $this->resolveProductId($semanticResult);

        if ($productId === null) {
            return null;
        }

        $trustedParent = $this->linkGuard->resolveTrustedParentLinkBySubject(
            $workspaceId,
            $connectorAccountId,
            $productId,
        );

        if (! $trustedParent->isTrusted()) {
            return null;
        }

        if (! $hasConfigurableParentOperation) {
            return new AdobeConfigurableCommandEvidence(
                commandKind: 'classification_transition',
                appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                reasonCode: 'configurable_classification_transition_requires_adobe_validation',
            );
        }

        if ($semanticResult->hasBlockingFindings()) {
            return new AdobeConfigurableCommandEvidence(
                commandKind: 'classification_transition',
                appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                reasonCode: 'inactive_only_configurable_family_requires_adobe_validation',
            );
        }

        return null;
    }

    private function hasOperationType(AdobeProductExportSemanticResult $semanticResult, string $operationType): bool
    {
        foreach ($semanticResult->operations as $operation) {
            if ($operation->operation === $operationType) {
                return true;
            }
        }

        return false;
    }

    private function resolveProductId(AdobeProductExportSemanticResult $semanticResult): ?int
    {
        foreach ($semanticResult->operations as $operation) {
            $productId = $operation->context['product_id'] ?? null;

            if (is_int($productId)) {
                return $productId;
            }

            if (is_string($productId) && ctype_digit($productId)) {
                return (int) $productId;
            }
        }

        if ($semanticResult->findings !== []) {
            foreach ($semanticResult->findings as $finding) {
                if (is_string($finding->subject) && ctype_digit($finding->subject)) {
                    return (int) $finding->subject;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<AdobeConfigurableCommandEvidence>  $evidence
     * @param  list<string>  $requiredVariantIds
     */
    private function allChildrenKnownApplied(array $evidence, array $requiredVariantIds): bool
    {
        foreach ($requiredVariantIds as $variantId) {
            $childEvidence = collect($evidence)->first(
                static fn (AdobeConfigurableCommandEvidence $entry): bool => $entry->commandKind === 'simple_child'
                    && $entry->variantId === $variantId,
            );

            if ($childEvidence === null
                || $childEvidence->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<AdobeConfigurableCommandEvidence>  $evidence
     * @param  list<AdobeConfigurableChildLinkDesiredState>  $requiredLinks
     */
    private function allChildLinksKnownApplied(array $evidence, array $requiredLinks): bool
    {
        foreach ($requiredLinks as $requiredLink) {
            $linkEvidence = collect($evidence)->first(
                static fn (AdobeConfigurableCommandEvidence $entry): bool => $entry->commandKind === 'child_link'
                    && $entry->variantId === $requiredLink->variantId,
            );

            if ($linkEvidence === null
                || $linkEvidence->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied
            ) {
                return false;
            }
        }

        return true;
    }

    private function mapSimpleChildEvidence(
        AdobeProductSimpleCommandResult $result,
        string $variantId,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'simple_child',
            appliedStateKnowledge: $result->appliedStateKnowledge,
            reasonCode: $result->evidence->reasonCode,
            subjectSku: $result->evidence->subjectSku,
            variantId: $variantId,
            consequentialWriteAttempts: $result->evidence->consequentialWriteAttempts,
            reconciliationGetAttempts: $result->evidence->reconciliationGetAttempts,
            externalRecordLinkPersisted: $result->evidence->externalRecordLinkPersisted,
            ownershipTrustSatisfied: $result->evidence->ownershipTrustSatisfied,
        );
    }
}
