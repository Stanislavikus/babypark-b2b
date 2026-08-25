<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncClient;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncContract;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncHandshake;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncRequestFactory;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncSimpleProductWriteRequest;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncSimpleProductWriteResult;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncVerifiedProduct;
use App\Support\Connectors\Transport\ConnectorHttpTransport;

final class AdobeStage3EValidationRunner
{
    public function __construct(
        private readonly AdobeStage3EValidationGuard $guard,
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeSafeSyncRequestFactory $requestFactory,
        private readonly ConnectorHttpTransport $transport,
        private readonly AdobeStage3EValidationEvidenceWriter $evidenceWriter,
    ) {}

    public function run(AdobeStage3EValidationRunInput $input): AdobeStage3EValidationRunResult
    {
        $runId = 'stage3e_'.bin2hex(random_bytes(8));
        $startedAt = now()->toIso8601String();
        $guard = $this->guard->evaluate($input);

        if (! $guard->passed || ! $guard->subject instanceof AdobeStage3EValidationResolvedSubject) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: $input->scenarioCode(),
                outcome: AdobeStage3EValidationOutcome::Fail,
                messages: ['Stage 3E validation preflight failed before HTTP.'],
                failureCodes: $guard->failureCodes,
            );
        }

        $subject = $guard->subject;
        $transport = new AdobeStage3EValidationTransportDecorator(
            $this->transport,
            $this->evidenceWriter,
        );
        $client = new AdobeSafeSyncClient(
            $this->contextFactory,
            $this->requestFactory,
            $transport,
        );

        try {
            $context = $this->contextFactory->create($subject->workspaceId, $subject->account->id);
        } catch (\Throwable $exception) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: $input->scenarioCode(),
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                failureCodes: ['request_context_creation_failed'],
                messages: [$exception->getMessage()],
            );
        }

        try {
            $handshake = $client->handshakeWithContext($context);
        } catch (\Throwable $exception) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: $input->scenarioCode(),
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                failureCodes: ['safe_sync_handshake_failed'],
                messages: [$exception->getMessage()],
            );
        }

        $handshakeAdmissionFailureCodes = $this->handshakeAdmissionFailureCodes($handshake);
        if ($handshakeAdmissionFailureCodes !== []) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: $input->scenarioCode(),
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                handshake: $handshake,
                failureCodes: $handshakeAdmissionFailureCodes,
                messages: ['Stage 3E validation handshake admission failed before pre-read/write scenario execution.'],
            );
        }

        try {
            $preRead = $client->readProductWithContext(
                $context,
                $subject->logicalEntityId,
                $subject->sku,
            );
        } catch (\Throwable $exception) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: $input->scenarioCode(),
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['safe_sync_pre_read_failed'],
                messages: [$exception->getMessage()],
            );
        }

        if ($preRead->typeId !== 'simple') {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: $input->scenarioCode(),
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['remote_product_not_simple'],
                messages: ['The resolved validation subject is not a simple Magento product.'],
            );
        }

        $this->evidenceWriter->recordScenarioEvent([
            'timestamp' => now()->toIso8601String(),
            'event' => 'pre_state_captured',
            'logical_entity_id' => $preRead->logicalEntityId,
            'sku' => $preRead->sku,
            'name_sha256' => hash('sha256', $preRead->name),
        ]);

        $mutatedName = $this->mutatedName($preRead->name, $runId);

        if ($input->simulateTransportLossAfterWrite) {
            $transport->armTransportLossAfterWrite(new AdobeStage3EValidationTransportArm(
                normalizedHost: $subject->normalizedHost,
                storeCode: $subject->storeCode,
                logicalEntityId: $subject->logicalEntityId,
            ));
        }

        $writeResult = $client->writeSimpleProductWithContext(
            $context,
            $subject->logicalEntityId,
            new AdobeSafeSyncSimpleProductWriteRequest(
                expectedSku: $subject->sku,
                name: $mutatedName,
            ),
        );

        $this->recordWriteResult('baseline_write_result', $writeResult);

        if ($input->simulateTransportLossAfterWrite) {
            return $this->completeTransportLossScenario(
                runId: $runId,
                startedAt: $startedAt,
                subject: $subject,
                handshake: $handshake,
                client: $client,
                context: $context,
                decorator: $transport,
                writeResult: $writeResult,
            );
        }

        return $this->completeBaselineScenario(
            runId: $runId,
            startedAt: $startedAt,
            subject: $subject,
            handshake: $handshake,
            client: $client,
            context: $context,
            originalName: $preRead->name,
            mutatedName: $mutatedName,
            writeResult: $writeResult,
            decorator: $transport,
            restoreAfterKnownApplied: $input->restoreAfterKnownApplied,
        );
    }

    private function completeBaselineScenario(
        string $runId,
        string $startedAt,
        AdobeStage3EValidationResolvedSubject $subject,
        AdobeSafeSyncHandshake $handshake,
        AdobeSafeSyncClient $client,
        #[\SensitiveParameter] object $context,
        string $originalName,
        string $mutatedName,
        AdobeSafeSyncSimpleProductWriteResult $writeResult,
        AdobeStage3EValidationTransportDecorator $decorator,
        bool $restoreAfterKnownApplied,
    ): AdobeStage3EValidationRunResult {
        if ($decorator->simpleProductWriteCount() !== 1) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'baseline_simple_write',
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['unexpected_consequential_write_count'],
                messages: ['Baseline scenario performed an unexpected number of consequential PUT requests.'],
            );
        }

        if ($writeResult->appliedStateKnowledge === AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous) {
            try {
                $reconciled = $client->readProductWithContext($context, $subject->logicalEntityId, $subject->sku);
                $this->recordReadOnlyReconciliation($reconciled);
            } catch (\Throwable $exception) {
                $this->evidenceWriter->recordScenarioEvent([
                    'timestamp' => now()->toIso8601String(),
                    'event' => 'read_only_reconciliation_failed',
                    'message' => $exception->getMessage(),
                ]);
            }

            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'baseline_simple_write',
                outcome: AdobeStage3EValidationOutcome::Inconclusive,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['baseline_write_ambiguous'],
                messages: ['Baseline write became ambiguous; automatic restore was forbidden.'],
            );
        }

        if ($writeResult->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'baseline_simple_write',
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['baseline_write_not_applied'],
                messages: [$writeResult->reasonCode],
            );
        }

        try {
            $verified = $client->readProductWithContext($context, $subject->logicalEntityId, $subject->sku);
        } catch (\Throwable $exception) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'baseline_simple_write',
                outcome: AdobeStage3EValidationOutcome::Inconclusive,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['post_write_entity_read_failed'],
                messages: [$exception->getMessage()],
            );
        }

        if ($verified->name !== $mutatedName) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'baseline_simple_write',
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['post_write_name_mismatch'],
                messages: ['Entity-bound post-write verification did not observe the mutated name.'],
            );
        }

        $this->evidenceWriter->recordScenarioEvent([
            'timestamp' => now()->toIso8601String(),
            'event' => 'post_write_verified',
            'name_sha256' => hash('sha256', $verified->name),
        ]);

        if (! $restoreAfterKnownApplied) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'baseline_simple_write',
                outcome: AdobeStage3EValidationOutcome::Pass,
                subject: $subject,
                handshake: $handshake,
                messages: ['Baseline simple write passed without restore.'],
            );
        }

        $restoreResult = $client->writeSimpleProductWithContext(
            $context,
            $subject->logicalEntityId,
            new AdobeSafeSyncSimpleProductWriteRequest(
                expectedSku: $subject->sku,
                name: $originalName,
            ),
        );

        $this->recordWriteResult('restore_write_result', $restoreResult);

        if ($decorator->simpleProductWriteCount() !== 2) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'baseline_simple_write',
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['unexpected_restore_write_count'],
                messages: ['Restore path exceeded the bounded consequential write count.'],
            );
        }

        if ($restoreResult->appliedStateKnowledge === AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous) {
            try {
                $reconciled = $client->readProductWithContext($context, $subject->logicalEntityId, $subject->sku);
                $this->recordReadOnlyReconciliation($reconciled);
            } catch (\Throwable $exception) {
                $this->evidenceWriter->recordScenarioEvent([
                    'timestamp' => now()->toIso8601String(),
                    'event' => 'restore_reconciliation_failed',
                    'message' => $exception->getMessage(),
                ]);
            }

            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'baseline_simple_write',
                outcome: AdobeStage3EValidationOutcome::Inconclusive,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['restore_write_ambiguous'],
                messages: ['Restore write became ambiguous and was not retried.'],
            );
        }

        if ($restoreResult->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::KnownApplied) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'baseline_simple_write',
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['restore_write_not_applied'],
                messages: [$restoreResult->reasonCode],
            );
        }

        try {
            $restored = $client->readProductWithContext($context, $subject->logicalEntityId, $subject->sku);
        } catch (\Throwable $exception) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'baseline_simple_write',
                outcome: AdobeStage3EValidationOutcome::Inconclusive,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['post_restore_entity_read_failed'],
                messages: [$exception->getMessage()],
            );
        }

        if ($restored->name !== $originalName) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'baseline_simple_write',
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['post_restore_name_mismatch'],
                messages: ['Entity-bound restore verification did not observe the original name.'],
            );
        }

        return $this->finalize(
            runId: $runId,
            startedAt: $startedAt,
            scenarioCode: 'baseline_simple_write',
            outcome: AdobeStage3EValidationOutcome::Pass,
            subject: $subject,
            handshake: $handshake,
            messages: ['Baseline simple write passed and completed one bounded restore.'],
        );
    }

    private function completeTransportLossScenario(
        string $runId,
        string $startedAt,
        AdobeStage3EValidationResolvedSubject $subject,
        AdobeSafeSyncHandshake $handshake,
        AdobeSafeSyncClient $client,
        #[\SensitiveParameter] object $context,
        AdobeStage3EValidationTransportDecorator $decorator,
        AdobeSafeSyncSimpleProductWriteResult $writeResult,
    ): AdobeStage3EValidationRunResult {
        if ($decorator->armedTransportLossFireCount() !== 1) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'transport_loss_after_write',
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['transport_loss_arm_did_not_fire_exactly_once'],
                messages: ['The validation-local transport loss arm did not fire exactly once.'],
            );
        }

        if ($decorator->simpleProductWriteCount() !== 1) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'transport_loss_after_write',
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['unexpected_consequential_write_count'],
                messages: ['Transport-loss scenario performed more than one consequential PUT request.'],
            );
        }

        if ($writeResult->appliedStateKnowledge !== AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'transport_loss_after_write',
                outcome: AdobeStage3EValidationOutcome::Fail,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['transport_loss_did_not_map_to_unknown_or_ambiguous'],
                messages: ['Transport loss did not map the write to UnknownOrAmbiguous.'],
            );
        }

        try {
            $reconciled = $client->readProductWithContext($context, $subject->logicalEntityId, $subject->sku);
            $this->recordReadOnlyReconciliation($reconciled);
        } catch (\Throwable $exception) {
            return $this->finalize(
                runId: $runId,
                startedAt: $startedAt,
                scenarioCode: 'transport_loss_after_write',
                outcome: AdobeStage3EValidationOutcome::Inconclusive,
                subject: $subject,
                handshake: $handshake,
                failureCodes: ['read_only_reconciliation_failed'],
                messages: [$exception->getMessage()],
            );
        }

        return $this->finalize(
            runId: $runId,
            startedAt: $startedAt,
            scenarioCode: 'transport_loss_after_write',
            outcome: AdobeStage3EValidationOutcome::Pass,
            subject: $subject,
            handshake: $handshake,
            messages: ['Transport-loss scenario proved client ambiguity and no automatic retry.'],
        );
    }

    private function mutatedName(string $originalName, string $runId): string
    {
        $suffix = ' [S3 '.substr($runId, -6).']';
        $base = $originalName === '' ? 'B2BVAL Product' : $originalName;

        return mb_substr($base, 0, max(1, 255 - strlen($suffix))).$suffix;
    }

    private function recordWriteResult(string $event, AdobeSafeSyncSimpleProductWriteResult $result): void
    {
        $this->evidenceWriter->recordScenarioEvent([
            'timestamp' => now()->toIso8601String(),
            'event' => $event,
            'applied_state' => $result->appliedStateKnowledge->value,
            'reason_code' => $result->reasonCode,
            'logical_entity_id' => $result->logicalEntityId,
            'sku' => $result->sku,
            'postcondition_verified' => $result->postconditionVerified,
            'consequential_write_attempts' => $result->consequentialWriteAttempts,
            'warning_codes' => $result->warningCodes,
        ]);
    }

    private function recordReadOnlyReconciliation(AdobeSafeSyncVerifiedProduct $product): void
    {
        $this->evidenceWriter->recordScenarioEvent([
            'timestamp' => now()->toIso8601String(),
            'event' => 'read_only_reconciliation',
            'logical_entity_id' => $product->logicalEntityId,
            'sku' => $product->sku,
            'name_sha256' => hash('sha256', $product->name),
        ]);
    }

    /**
     * @param  list<string>  $messages
     * @param  list<string>  $failureCodes
     */
    private function finalize(
        string $runId,
        string $startedAt,
        string $scenarioCode,
        AdobeStage3EValidationOutcome $outcome,
        array $messages = [],
        array $failureCodes = [],
        ?AdobeStage3EValidationResolvedSubject $subject = null,
        ?AdobeSafeSyncHandshake $handshake = null,
    ): AdobeStage3EValidationRunResult {
        $artifactPath = $this->evidenceWriter->write($runId, [
            'run_id' => $runId,
            'scenario_code' => $scenarioCode,
            'started_at' => $startedAt,
            'finished_at' => now()->toIso8601String(),
            'result' => $outcome->value,
            'connector_account_id' => $subject?->account->id,
            'store_code' => $subject?->storeCode,
            'normalized_target_host' => $subject?->normalizedHost,
            'product_variant_id' => $subject?->variant->id,
            'logical_entity_id' => $subject?->logicalEntityId,
            'sku' => $subject?->sku,
            'contract_version' => $handshake?->contractVersion,
            'module_version' => $handshake?->moduleVersion,
            'supported_operation_families' => $handshake?->supportedOperationFamilies,
            'failure_codes' => array_values(array_unique($failureCodes)),
            'messages' => $messages,
            'support_remains_false' => true,
            'live_writer_consumption_changed' => false,
            'real_target_certification_executed_in_pr' => false,
        ]);

        return new AdobeStage3EValidationRunResult(
            outcome: $outcome,
            artifactPath: $artifactPath,
            messages: $messages,
            failureCodes: array_values(array_unique($failureCodes)),
        );
    }

    /**
     * @return list<string>
     */
    private function handshakeAdmissionFailureCodes(AdobeSafeSyncHandshake $handshake): array
    {
        $failureCodes = [];

        if (! in_array(AdobeSafeSyncContract::SIMPLE_PRODUCT_WRITE_FAMILY, $handshake->supportedOperationFamilies, true)) {
            $failureCodes[] = 'safe_sync_simple_write_family_not_advertised';
        }

        if (! $this->isComparableSemanticVersion($handshake->moduleVersion)) {
            $failureCodes[] = 'safe_sync_module_version_not_comparable';

            return $failureCodes;
        }

        if (version_compare($handshake->moduleVersion, '0.2.1', '<')) {
            $failureCodes[] = 'safe_sync_module_version_below_s2_minimum';
        }

        return $failureCodes;
    }

    private function isComparableSemanticVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1;
    }
}
