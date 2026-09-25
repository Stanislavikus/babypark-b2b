<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;
use App\Support\Connectors\ConnectorAccountOperationLock;
use App\Support\Sync\Live\SyncLiveConsequentialWriteGate;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class AdobeConfigurableProductCommandCoordinator
{
    private readonly AdobeProductModulelessSimpleCreateExecutor $simpleChildCreateExecutor;

    public function __construct(
        private readonly AdobeConfigurableDesiredStateCompiler $desiredStateCompiler,
        private readonly AdobeProductSimpleCommandExecutor $simpleChildExecutor,
        private readonly AdobeConfigurableParentCommandExecutor $parentExecutor,
        private readonly AdobeConfigurableOptionCommandExecutor $optionExecutor,
        private readonly AdobeConfigurableChildLinkCommandExecutor $childLinkExecutor,
        private readonly AdobeConfigurableInactiveLinkedVariantLifecycleExecutor $inactiveLifecycleExecutor,
        private readonly AdobeConfigurableAppliedStateAggregator $aggregator,
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
        ?AdobeProductModulelessSimpleCreateExecutor $simpleChildCreateExecutor = null,
    ) {
        $this->simpleChildCreateExecutor = $simpleChildCreateExecutor
            ?? app(AdobeProductModulelessSimpleCreateExecutor::class);
    }

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

        $trustedExistingParentSku = $this->resolveTrustedExistingParentSku(
            $workspaceId,
            $connectorAccountId,
            $semanticResult,
        );

        try {
            $desiredState = $this->desiredStateCompiler->compile(
                $semanticResult,
                $workspaceId,
                $metadata,
                $trustedExistingParentSku,
            );
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

        if ($consequentialWriteGate !== null
            && (! $consequentialWriteGate->permitsConsequentialWrite()
                || ! $consequentialWriteGate->permitsProductExecution())
        ) {
            return $this->singleEvidenceResult(new AdobeConfigurableCommandEvidence(
                commandKind: 'configurable_family',
                appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                reasonCode: 'consequential_write_gate_closed',
                subjectSku: $desiredState->parentSku,
            ));
        }

        if ($consequentialWriteGate !== null
            && $consequentialWriteGate->permitsConsequentialWrite()
            && $consequentialWriteGate->permitsProductExecution()
        ) {
            $parentPreflightEvidence = $this->parentExecutor->preflight($input);

            if ($parentPreflightEvidence !== null) {
                return new AdobeConfigurableProductExecutionResult(
                    outcome: $this->aggregator->aggregate([$parentPreflightEvidence]),
                    commandEvidence: [$parentPreflightEvidence],
                );
            }
        }

        foreach ($desiredState->activeChildVariantIds as $variantId) {
            $lookup = $this->linkGuard->resolveTrustedVariantLinkBySubject($workspaceId, $connectorAccountId, $variantId);
            $simpleInput = new AdobeProductSimpleCommandInput(
                workspaceId: $workspaceId,
                connectorAccountId: $connectorAccountId,
                semanticResult: $semanticResult,
                adobeBaseCurrency: $adobeBaseCurrency,
                consequentialWriteGate: $consequentialWriteGate,
            );
            $childPreflight = $lookup->isTrusted()
                ? $this->simpleChildExecutor->preflightSimpleChild($simpleInput, $variantId)
                : $this->simpleChildCreateExecutor->preflightSimpleChild($simpleInput, $variantId);
            if ($childPreflight !== null) {
                $mapped = $this->mapSimpleChildEvidence($childPreflight, $variantId);

                return new AdobeConfigurableProductExecutionResult(
                    outcome: $this->aggregator->aggregate([$mapped]),
                    commandEvidence: [$mapped],
                );
            }
        }

        try {
            return Cache::lock(
                ConnectorAccountOperationLock::cacheKey($connectorAccountId),
                $this->operationLockSeconds(),
            )->block(5, fn (): AdobeConfigurableProductExecutionResult => $this->executeLocked($input));
        } catch (LockTimeoutException) {
            $lockEvidence = new AdobeConfigurableCommandEvidence(
                commandKind: 'configurable_family',
                appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
                reasonCode: 'configurable_family_account_lock_timeout',
                subjectSku: $desiredState->parentSku,
            );

            return new AdobeConfigurableProductExecutionResult(
                outcome: $this->aggregator->aggregate([$lockEvidence]),
                commandEvidence: [$lockEvidence],
            );
        }
    }

    private function executeLocked(AdobeConfigurableCommandInput $input): AdobeConfigurableProductExecutionResult
    {
        $desiredState = $input->desiredState;
        $parentLookup = $this->linkGuard->resolveTrustedParentLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredState->productId,
        );
        $platformCreatedFamily = ! $parentLookup->isTrusted()
            || $parentLookup->link?->hasPlatformCreatedTrust() === true;
        $createResumeMode = ! $parentLookup->isTrusted()
            || ($parentLookup->link?->hasPlatformCreatedTrust() === true
                && ! $this->platformCreatedFamilyReadyForOrdinaryExecution($input));
        $coreInput = $createResumeMode ? $this->disabledCoreInput($input) : $input;

        if ($input->consequentialWriteGate === null
            || ! $input->consequentialWriteGate->permitsConsequentialWrite()
            || ! $input->consequentialWriteGate->permitsProductExecution()
        ) {
            return $this->singleEvidenceResult(new AdobeConfigurableCommandEvidence(
                commandKind: 'configurable_family',
                appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                reasonCode: 'consequential_write_gate_closed',
                subjectSku: $desiredState->parentSku,
            ));
        }

        $parentRecheck = $this->parentExecutor->preflight($coreInput);
        if ($parentRecheck !== null) {
            return $this->singleEvidenceResult($parentRecheck);
        }

        foreach ($desiredState->activeChildVariantIds as $variantId) {
            $lookup = $this->linkGuard->resolveTrustedVariantLinkBySubject(
                $input->workspaceId,
                $input->connectorAccountId,
                $variantId,
            );
            $preflight = $lookup->isTrusted()
                ? $this->simpleChildExecutor->preflightSimpleChild($this->simpleInput($input), $variantId)
                : $this->simpleChildCreateExecutor->preflightSimpleChild($this->simpleInput($input), $variantId);
            if ($preflight !== null) {
                return $this->singleEvidenceResult($this->mapSimpleChildEvidence($preflight, $variantId));
            }
        }

        $evidence = [];
        foreach ($desiredState->activeChildVariantIds as $variantId) {
            $trusted = $this->linkGuard->resolveTrustedVariantLinkBySubject(
                $input->workspaceId,
                $input->connectorAccountId,
                $variantId,
            );
            $childResult = $trusted->isTrusted()
                ? $this->simpleChildExecutor->executeSimpleChild($this->simpleInput($input), $variantId)
                : $this->simpleChildCreateExecutor->executeSimpleChild($this->simpleInput($input), $variantId);
            $childEvidence = $this->mapSimpleChildEvidence($childResult, $variantId);
            $evidence[] = $childEvidence;

            if ($childEvidence->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied) {
                return $this->evidenceResult($evidence);
            }
        }

        $parentEvidence = $this->parentExecutor->execute($coreInput);
        $evidence[] = $parentEvidence;
        if ($parentEvidence->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied) {
            return $this->evidenceResult($evidence);
        }

        if (! $platformCreatedFamily) {
            foreach ($desiredState->options as $option) {
                $blocked = $this->optionExecutor->preflightExistingUpdateOnly($input, $option);
                if ($blocked !== null) {
                    $evidence[] = $blocked;

                    return $this->evidenceResult($evidence);
                }
            }
        } else {
            foreach ($desiredState->options as $option) {
                $optionEvidence = $this->optionExecutor->executePreLink($coreInput, $option);
                $evidence[] = $optionEvidence;
                if ($optionEvidence->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied) {
                    return $this->evidenceResult($evidence);
                }
            }
        }

        foreach ($desiredState->childLinks as $link) {
            $linkEvidence = $this->childLinkExecutor->executeTrustedRelinkOnly($coreInput, $link);
            $evidence[] = $linkEvidence;
            if ($linkEvidence->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied) {
                return $this->evidenceResult($evidence);
            }
        }

        foreach ($desiredState->options as $option) {
            $optionEvidence = $platformCreatedFamily
                ? $this->optionExecutor->execute($coreInput, $option)
                : $this->optionExecutor->executeExistingUpdateOnly($input, $option);
            $evidence[] = $optionEvidence;
            if ($optionEvidence->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied) {
                return $this->evidenceResult($evidence);
            }
        }

        if ($platformCreatedFamily) {
            $bootstrapEvidence = $this->parentExecutor->verifyBootstrapNormalized($input);
            $evidence[] = $bootstrapEvidence;
            if ($bootstrapEvidence->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied) {
                return $this->evidenceResult($evidence);
            }
        }

        $lifecycle = $this->inactiveLifecycleExecutor->execute($coreInput);
        $evidence = array_merge($evidence, $lifecycle);
        foreach ($lifecycle as $entry) {
            if ($entry->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied) {
                return $this->evidenceResult($evidence);
            }
        }

        if ($createResumeMode) {
            $finalParent = $this->parentExecutor->execute($input);
            $evidence[] = $finalParent;
        }

        return $this->evidenceResult($evidence);
    }

    private function platformCreatedFamilyReadyForOrdinaryExecution(AdobeConfigurableCommandInput $input): bool
    {
        foreach ($input->desiredState->options as $option) {
            if ($this->optionExecutor->executeNoOpOnly($input, $option)->appliedStateKnowledge
                !== AdobeProductAppliedStateKnowledge::KnownApplied
            ) {
                return false;
            }
        }

        foreach ($input->desiredState->childLinks as $link) {
            if ($this->childLinkExecutor->executeNoOpOnly($input, $link)->appliedStateKnowledge
                !== AdobeProductAppliedStateKnowledge::KnownApplied
            ) {
                return false;
            }
        }

        if ($this->parentExecutor->verifyBootstrapNormalized($input)->appliedStateKnowledge
            !== AdobeProductAppliedStateKnowledge::KnownApplied
        ) {
            return false;
        }

        foreach ($this->inactiveLifecycleExecutor->executeNoOpOnly($input) as $evidence) {
            if ($evidence->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied) {
                return false;
            }
        }

        return true;
    }

    private function disabledCoreInput(AdobeConfigurableCommandInput $input): AdobeConfigurableCommandInput
    {
        $parent = $input->desiredState->parent;
        $disabledParent = new AdobeProductParentDesiredState(
            productId: $parent->productId,
            sku: $parent->sku,
            name: $parent->name,
            attributeSetId: $parent->attributeSetId,
            typeId: $parent->typeId,
            status: 2,
            visibility: $parent->visibility,
            customAttributes: $parent->customAttributes,
        );
        $state = new AdobeConfigurableDesiredState(
            productId: $input->desiredState->productId,
            parentSku: $input->desiredState->parentSku,
            parent: $disabledParent,
            options: $input->desiredState->options,
            activeChildVariantIds: $input->desiredState->activeChildVariantIds,
            childLinks: $input->desiredState->childLinks,
            createParent: $input->desiredState->createParent,
            bootstrapAttributeCodes: $input->desiredState->bootstrapAttributeCodes,
        );

        return new AdobeConfigurableCommandInput(
            workspaceId: $input->workspaceId,
            connectorAccountId: $input->connectorAccountId,
            semanticResult: $input->semanticResult,
            desiredState: $state,
            adobeBaseCurrency: $input->adobeBaseCurrency,
            metadata: $input->metadata,
            consequentialWriteGate: $input->consequentialWriteGate,
        );
    }

    private function simpleInput(AdobeConfigurableCommandInput $input): AdobeProductSimpleCommandInput
    {
        return new AdobeProductSimpleCommandInput(
            workspaceId: $input->workspaceId,
            connectorAccountId: $input->connectorAccountId,
            semanticResult: $input->semanticResult,
            adobeBaseCurrency: $input->adobeBaseCurrency,
            consequentialWriteGate: $input->consequentialWriteGate,
        );
    }

    /** @param list<AdobeConfigurableCommandEvidence> $evidence */
    private function evidenceResult(array $evidence): AdobeConfigurableProductExecutionResult
    {
        return new AdobeConfigurableProductExecutionResult(
            outcome: $this->aggregator->aggregate($evidence),
            commandEvidence: $evidence,
        );
    }

    private function singleEvidenceResult(AdobeConfigurableCommandEvidence $evidence): AdobeConfigurableProductExecutionResult
    {
        return $this->evidenceResult([$evidence]);
    }

    private function operationLockSeconds(): int
    {
        return max(
            180,
            (int) config('sync_runtime.live_job_timeout_seconds')
                + (int) config('sync_runtime.max_inflight_external_request_seconds'),
        );
    }

    private function resolveTrustedExistingParentSku(
        string $workspaceId,
        string $connectorAccountId,
        AdobeProductExportSemanticResult $semanticResult,
    ): ?string {
        $productId = $this->resolveProductId($semanticResult);

        if ($productId === null) {
            return null;
        }

        $lookup = $this->linkGuard->resolveTrustedParentLinkBySubject(
            $workspaceId,
            $connectorAccountId,
            $productId,
        );

        if (! $lookup->isTrusted() || $lookup->link === null) {
            return null;
        }

        $parentSku = $lookup->link->external_identifier;

        return is_string($parentSku) && trim($parentSku) !== '' ? trim($parentSku) : null;
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
            writeAccessClassification: $result->evidence->writeAccessClassification,
        );
    }
}
