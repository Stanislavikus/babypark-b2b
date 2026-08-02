<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Enums\ConnectorDiscoveryRunLifecycleErrorCode;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorErrorActionability;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\ConnectorSchemaSource;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\ConnectorDiscoveryNormalizedField;
use App\Support\Connectors\ConnectorDiscoverySnapshotCandidate;
use Illuminate\Support\Facades\DB;

final class ConnectorDiscoveryRunPersistence
{
    private const int LOCK_EXPIRE_SECONDS = 1100;

    public function __construct(
        private readonly ConnectorDiscoverySourceResolver $sourceResolver,
    ) {}

    public function persistAttemptDurationOnly(
        string $workspaceId,
        string $connectorAccountId,
        string $discoveryRunId,
        int $durationMs,
    ): void {
        DB::transaction(function () use ($workspaceId, $connectorAccountId, $discoveryRunId, $durationMs): void {
            $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $discoveryRunId);

            if ($row === null || $row->isTerminal()) {
                return;
            }

            $row->update([
                'duration_ms' => ($row->duration_ms ?? 0) + $durationMs,
            ]);
        });
    }

    /**
     * @return ?int Release delay in seconds, or null when terminal / no release.
     */
    public function finalizeAfterVendorAttempt(
        string $workspaceId,
        string $connectorAccountId,
        string $discoveryRunId,
        ConnectorDiscoveryAttemptResult $result,
        int $attemptDurationMs,
        \DateTimeInterface $retryUntilAt,
    ): ?int {
        return DB::transaction(function () use (
            $workspaceId,
            $connectorAccountId,
            $discoveryRunId,
            $result,
            $attemptDurationMs,
            $retryUntilAt,
        ): ?int {
            $account = $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $discoveryRunId);

            if ($row === null || $row->isTerminal()) {
                return null;
            }

            $durationMs = ($row->duration_ms ?? 0) + $attemptDurationMs;
            $classification = $this->classificationFromResult($result);

            if ($result->succeeded) {
                $this->publishSnapshot($account, $row, $result->snapshotCandidate, $durationMs);
                $this->applyVendorProjectionIfNewest($account, $row, succeeded: true);

                return null;
            }

            $actionability = $result->actionability();

            if ($actionability !== ConnectorErrorActionability::AutomaticRetry) {
                $this->applyTerminalUpdate($row, ConnectorDiscoveryRunStatus::Failed, $classification, $durationMs);
                $this->applyVendorProjectionIfNewest($account, $row, succeeded: false);

                return null;
            }

            if ($row->execution_attempts >= 3) {
                $this->applyTerminalUpdate($row, ConnectorDiscoveryRunStatus::Failed, $classification, $durationMs);
                $this->applyVendorProjectionIfNewest($account, $row, succeeded: false);

                return null;
            }

            $delay = $this->computeRetryDelay($result, $row->execution_attempts);

            if (now()->addSeconds($delay)->gte($retryUntilAt)) {
                $this->applyTerminalUpdate($row, ConnectorDiscoveryRunStatus::Failed, $classification, $durationMs);
                $this->applyVendorProjectionIfNewest($account, $row, succeeded: false);

                return null;
            }

            $row->update(array_merge($classification, [
                'status' => ConnectorDiscoveryRunStatus::Running,
                'duration_ms' => $durationMs,
                'next_attempt_at' => now()->addSeconds($delay),
            ]));

            return $delay;
        });
    }

    public function writeLifecycleFailure(
        string $workspaceId,
        string $connectorAccountId,
        string $discoveryRunId,
        ConnectorDiscoveryRunLifecycleErrorCode $code,
    ): void {
        DB::transaction(function () use ($workspaceId, $connectorAccountId, $discoveryRunId, $code): void {
            $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $discoveryRunId);

            if ($row === null || $row->isTerminal()) {
                return;
            }

            $row->update([
                'status' => ConnectorDiscoveryRunStatus::Failed,
                'cause_category' => $code->cause(),
                'actionability' => $code->actionability(),
                'error_code' => $code->value,
                'user_message_key' => $code->messageKey(),
                'technical_summary' => $code->technicalSummary(),
                'finished_at' => now(),
                'next_attempt_at' => null,
                'started_at' => $row->started_at ?? $row->created_at,
            ]);
        });
    }

    public function terminalizeWithStoredVendorClassification(
        string $workspaceId,
        string $connectorAccountId,
        string $discoveryRunId,
    ): void {
        DB::transaction(function () use ($workspaceId, $connectorAccountId, $discoveryRunId): void {
            $account = $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $discoveryRunId);

            if ($row === null || $row->isTerminal()) {
                return;
            }

            if (! $row->hasVendorClassification()) {
                $this->writeLifecycleFailureInTransaction($row, ConnectorDiscoveryRunLifecycleErrorCode::JobFailed);

                return;
            }

            $row->update([
                'status' => ConnectorDiscoveryRunStatus::Failed,
                'finished_at' => now(),
                'next_attempt_at' => null,
            ]);

            $this->applyVendorProjectionIfNewest($account, $row, succeeded: false);
        });
    }

    public function terminalizeAttemptsExhausted(
        string $workspaceId,
        string $connectorAccountId,
        string $discoveryRunId,
    ): void {
        DB::transaction(function () use ($workspaceId, $connectorAccountId, $discoveryRunId): void {
            $account = $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $discoveryRunId);

            if ($row === null || $row->isTerminal()) {
                return;
            }

            if ($row->hasVendorClassification()) {
                $row->update([
                    'status' => ConnectorDiscoveryRunStatus::Failed,
                    'finished_at' => now(),
                    'next_attempt_at' => null,
                ]);
                $this->applyVendorProjectionIfNewest($account, $row, succeeded: false);

                return;
            }

            $this->writeLifecycleFailureInTransaction(
                $row,
                ConnectorDiscoveryRunLifecycleErrorCode::AttemptsExhaustedWithoutResult,
            );
        });
    }

    public function terminalizeAccountDisabledBeforeExecution(
        string $workspaceId,
        string $connectorAccountId,
        string $discoveryRunId,
    ): void {
        DB::transaction(function () use ($workspaceId, $connectorAccountId, $discoveryRunId): void {
            $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $discoveryRunId);

            if ($row === null || $row->isTerminal()) {
                return;
            }

            $this->writeLifecycleFailureInTransaction(
                $row,
                ConnectorDiscoveryRunLifecycleErrorCode::AccountDisabledBeforeExecution,
            );
        });
    }

    /**
     * @return array{reserved: bool, releaseDelaySeconds: ?int}
     */
    public function reserveExecutionSlot(
        string $workspaceId,
        string $connectorAccountId,
        string $discoveryRunId,
    ): array {
        return DB::transaction(function () use ($workspaceId, $connectorAccountId, $discoveryRunId): array {
            $account = $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $discoveryRunId);

            if ($row === null || $row->isTerminal()) {
                return ['reserved' => false, 'releaseDelaySeconds' => null];
            }

            if (! $account->is_enabled) {
                $this->writeLifecycleFailureInTransaction(
                    $row,
                    ConnectorDiscoveryRunLifecycleErrorCode::AccountDisabledBeforeExecution,
                );

                return ['reserved' => false, 'releaseDelaySeconds' => null];
            }

            $source = ConnectorSchemaSource::query()->find($row->connector_schema_source_id);

            if ($source === null || ! $this->sourceResolver->reverify($account, $source)) {
                $this->writeLifecycleFailureInTransaction(
                    $row,
                    ConnectorDiscoveryRunLifecycleErrorCode::SourceInvalidBeforeExecution,
                );

                return ['reserved' => false, 'releaseDelaySeconds' => null];
            }

            if ($row->next_attempt_at !== null && $row->next_attempt_at->isFuture()) {
                $remainingSeconds = max(1, $row->next_attempt_at->getTimestamp() - now()->getTimestamp());

                return ['reserved' => false, 'releaseDelaySeconds' => $remainingSeconds];
            }

            if ($row->execution_attempts >= 3) {
                if ($row->hasVendorClassification()) {
                    $row->update([
                        'status' => ConnectorDiscoveryRunStatus::Failed,
                        'finished_at' => now(),
                        'next_attempt_at' => null,
                    ]);
                    $this->applyVendorProjectionIfNewest($account, $row, succeeded: false);
                } else {
                    $this->writeLifecycleFailureInTransaction(
                        $row,
                        ConnectorDiscoveryRunLifecycleErrorCode::AttemptsExhaustedWithoutResult,
                    );
                }

                return ['reserved' => false, 'releaseDelaySeconds' => null];
            }

            $updates = [
                'execution_attempts' => $row->execution_attempts + 1,
                'next_attempt_at' => null,
            ];

            if ($row->status !== ConnectorDiscoveryRunStatus::Running) {
                $updates['status'] = ConnectorDiscoveryRunStatus::Running;
            }

            if ($row->started_at === null) {
                $updates['started_at'] = now();
            }

            $row->update($updates);

            return ['reserved' => true, 'releaseDelaySeconds' => null];
        });
    }

    public function recoverStaleRowIfNeeded(
        string $workspaceId,
        ConnectorAccount $account,
        ConnectorDiscoveryRun $row,
    ): void {
        if (! $this->isStale($row)) {
            return;
        }

        DB::transaction(function () use ($workspaceId, $account, $row): void {
            $lockedAccount = $this->lockAccount($workspaceId, $account->id);
            $lockedRow = $this->lockHistoryRow($workspaceId, $account->id, $row->id);

            if ($lockedRow === null || $lockedRow->isTerminal() || ! $this->isStale($lockedRow)) {
                return;
            }

            $this->recoverStaleRow($lockedAccount, $lockedRow);
        });
    }

    public function recoverStaleRow(
        ConnectorAccount $account,
        ConnectorDiscoveryRun $row,
    ): void {
        if ($row->hasVendorClassification()) {
            $row->update([
                'status' => ConnectorDiscoveryRunStatus::Failed,
                'finished_at' => now(),
                'next_attempt_at' => null,
            ]);
            $this->applyVendorProjectionIfNewest($account, $row, succeeded: false);

            return;
        }

        $lifecycleCode = $row->status === ConnectorDiscoveryRunStatus::Queued
            ? ConnectorDiscoveryRunLifecycleErrorCode::DispatchFailed
            : ConnectorDiscoveryRunLifecycleErrorCode::JobFailed;

        $this->writeLifecycleFailureInTransaction($row, $lifecycleCode);
    }

    public function isStale(ConnectorDiscoveryRun $row): bool
    {
        if ($row->retry_until_at === null) {
            return false;
        }

        if ($row->status === ConnectorDiscoveryRunStatus::Queued) {
            return $row->retry_until_at->isPast();
        }

        if ($row->status === ConnectorDiscoveryRunStatus::Running) {
            return $row->retry_until_at->copy()->addSeconds(self::LOCK_EXPIRE_SECONDS)->isPast();
        }

        return false;
    }

    private function lockAccount(string $workspaceId, string $connectorAccountId): ConnectorAccount
    {
        return ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectorAccountId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockHistoryRow(
        string $workspaceId,
        string $connectorAccountId,
        string $discoveryRunId,
    ): ?ConnectorDiscoveryRun {
        return ConnectorDiscoveryRun::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('id', $discoveryRunId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function classificationFromResult(ConnectorDiscoveryAttemptResult $result): array
    {
        return [
            'cause_category' => $result->cause(),
            'actionability' => $result->actionability(),
            'error_code' => $result->errorCode?->value,
            'http_status' => $result->httpStatus,
            'user_message_key' => $result->messageKey(),
            'technical_summary' => $result->technicalSummary(),
            'vendor_request_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $classification
     */
    private function applyTerminalUpdate(
        ConnectorDiscoveryRun $row,
        ConnectorDiscoveryRunStatus $status,
        array $classification,
        int $durationMs,
    ): void {
        $row->update(array_merge($classification, [
            'status' => $status,
            'duration_ms' => $durationMs,
            'finished_at' => now(),
            'next_attempt_at' => null,
            'fields_received' => null,
            'fields_normalized' => null,
        ]));
    }

    private function writeLifecycleFailureInTransaction(
        ConnectorDiscoveryRun $row,
        ConnectorDiscoveryRunLifecycleErrorCode $code,
    ): void {
        $row->update([
            'status' => ConnectorDiscoveryRunStatus::Failed,
            'cause_category' => $code->cause(),
            'actionability' => $code->actionability(),
            'error_code' => $code->value,
            'user_message_key' => $code->messageKey(),
            'technical_summary' => $code->technicalSummary(),
            'finished_at' => now(),
            'next_attempt_at' => null,
            'started_at' => $row->started_at ?? $row->created_at,
        ]);
    }

    private function publishSnapshot(
        ConnectorAccount $account,
        ConnectorDiscoveryRun $row,
        ConnectorDiscoverySnapshotCandidate $candidate,
        int $durationMs,
    ): void {
        $previousSnapshot = ConnectorSchemaSnapshot::withoutWorkspaceScope()
            ->where('workspace_id', $row->workspace_id)
            ->where('connector_account_id', $row->connector_account_id)
            ->where('connector_schema_source_id', $row->connector_schema_source_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $source = ConnectorSchemaSource::query()->findOrFail($row->connector_schema_source_id);

        $snapshot = ConnectorSchemaSnapshot::withoutWorkspaceScope()->create([
            'workspace_id' => $row->workspace_id,
            'connector_account_id' => $row->connector_account_id,
            'connector_schema_source_id' => $row->connector_schema_source_id,
            'discovery_run_id' => $row->id,
            'previous_snapshot_id' => $previousSnapshot?->id,
            'schema_version' => $source->schema_version,
            'field_count' => $candidate->fieldsReceived(),
            'canonical_hash' => $candidate->canonicalHash,
            'captured_at' => $candidate->capturedAt,
        ]);

        foreach ($candidate->fields as $index => $normalizedField) {
            $this->createSnapshotField($row, $snapshot, $normalizedField, $index);
        }

        $row->update([
            'status' => ConnectorDiscoveryRunStatus::Succeeded,
            'snapshot_id' => $snapshot->id,
            'previous_snapshot_id' => $previousSnapshot?->id,
            'fields_received' => $candidate->fieldsReceived(),
            'fields_normalized' => $candidate->fieldsNormalized(),
            'duration_ms' => $durationMs,
            'finished_at' => now(),
            'next_attempt_at' => null,
            'cause_category' => null,
            'actionability' => null,
            'error_code' => null,
            'http_status' => null,
            'user_message_key' => null,
            'technical_summary' => null,
            'vendor_request_id' => null,
        ]);
    }

    private function createSnapshotField(
        ConnectorDiscoveryRun $row,
        ConnectorSchemaSnapshot $snapshot,
        ConnectorDiscoveryNormalizedField $normalizedField,
        int $index,
    ): void {
        $field = $normalizedField->field;

        ConnectorSchemaSnapshotField::withoutWorkspaceScope()->create([
            'workspace_id' => $row->workspace_id,
            'snapshot_id' => $snapshot->id,
            'external_field_key' => $field->externalFieldKey(),
            'external_label' => $field->externalLabel(),
            'normalized_data_type' => $field->normalizedDataType(),
            'is_required' => $field->isRequired(),
            'is_multi_value' => $field->isMultiValue(),
            'is_localizable' => $field->isLocalizable(),
            'external_scope' => $field->externalScope(),
            'normalized_payload' => $field->normalizedPayload()->toCanonicalObject(),
            'canonical_hash' => $normalizedField->canonicalHash,
            'sort_order' => $field->sortOrder() ?? $index,
        ]);
    }

    private function applyVendorProjectionIfNewest(
        ConnectorAccount $account,
        ConnectorDiscoveryRun $row,
        bool $succeeded,
    ): void {
        if (! $this->isNewestRunForAccount($account, $row)) {
            return;
        }

        $this->applyVendorProjection($account, $row, $succeeded);
    }

    private function isNewestRunForAccount(ConnectorAccount $account, ConnectorDiscoveryRun $row): bool
    {
        $newest = ConnectorDiscoveryRun::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $newest !== null && $newest->id === $row->id;
    }

    private function applyVendorProjection(
        ConnectorAccount $account,
        ConnectorDiscoveryRun $row,
        bool $succeeded,
    ): void {
        if (! $account->is_enabled) {
            return;
        }

        $finishedAt = $row->finished_at ?? now();

        if ($succeeded) {
            $account->update([
                'connection_status' => ConnectorAccountConnectionStatus::Connected,
                'last_discovery_at' => $finishedAt,
                'last_successful_discovery_at' => $finishedAt,
                'last_error_cause' => null,
                'last_error_actionability' => null,
                'last_error_message_key' => null,
                'last_error_at' => null,
            ]);

            return;
        }

        $account->update([
            'last_discovery_at' => $finishedAt,
        ]);

        $actionability = $row->actionability;

        if ($actionability === null) {
            return;
        }

        $connectionStatus = match ($actionability) {
            ConnectorErrorActionability::AutomaticRetry => ConnectorAccountConnectionStatus::TemporarilyUnavailable,
            ConnectorErrorActionability::UserActionRequired,
            ConnectorErrorActionability::WorkspaceAdminRequired,
            ConnectorErrorActionability::SupportRequired => ConnectorAccountConnectionStatus::AttentionRequired,
        };

        $account->update([
            'connection_status' => $connectionStatus,
            'last_error_cause' => $row->cause_category,
            'last_error_actionability' => $row->actionability,
            'last_error_message_key' => $row->user_message_key,
            'last_error_at' => $finishedAt,
        ]);
    }

    private function computeRetryDelay(ConnectorDiscoveryAttemptResult $result, int $executionAttempts): int
    {
        if (
            $result->errorCode === ConnectorDiscoveryRunErrorCode::AdobeRateLimited
            && $result->httpStatus === 429
            && $result->retryAfterSeconds !== null
        ) {
            return $result->retryAfterSeconds;
        }

        $base = $executionAttempts === 1 ? 60 : 300;

        return (int) ceil($base / 2) + random_int(0, (int) floor($base / 2));
    }
}
