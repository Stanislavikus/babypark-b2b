<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Enums\ConnectorConnectionCheckLifecycleErrorCode;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorErrorActionability;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use Illuminate\Support\Facades\DB;

final class ConnectorConnectionCheckPersistence
{
    public function persistAttemptDurationOnly(
        string $workspaceId,
        string $connectorAccountId,
        string $connectionCheckId,
        int $durationMs,
    ): void {
        DB::transaction(function () use ($workspaceId, $connectorAccountId, $connectionCheckId, $durationMs): void {
            $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $connectionCheckId);

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
        string $connectionCheckId,
        ConnectorConnectionCheckResult $result,
        int $attemptDurationMs,
        \DateTimeInterface $retryUntilAt,
    ): ?int {
        return DB::transaction(function () use (
            $workspaceId,
            $connectorAccountId,
            $connectionCheckId,
            $result,
            $attemptDurationMs,
            $retryUntilAt,
        ): ?int {
            $account = $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $connectionCheckId);

            if ($row === null || $row->isTerminal()) {
                return null;
            }

            $durationMs = ($row->duration_ms ?? 0) + $attemptDurationMs;
            $classification = $this->classificationFromResult($result);

            if ($result->succeeded) {
                $this->applyTerminalUpdate($row, ConnectorConnectionCheckStatus::Succeeded, $classification, $durationMs);
                $this->applyVendorProjection($account, $row, succeeded: true);

                return null;
            }

            $actionability = $result->actionability();

            if ($actionability !== ConnectorErrorActionability::AutomaticRetry) {
                $this->applyTerminalUpdate($row, ConnectorConnectionCheckStatus::Failed, $classification, $durationMs);
                $this->applyVendorProjection($account, $row, succeeded: false);

                return null;
            }

            if ($row->execution_attempts >= 3) {
                $this->applyTerminalUpdate($row, ConnectorConnectionCheckStatus::Failed, $classification, $durationMs);
                $this->applyVendorProjection($account, $row, succeeded: false);

                return null;
            }

            $delay = $this->computeRetryDelay($result, $row->execution_attempts);

            if (now()->addSeconds($delay)->gte($retryUntilAt)) {
                $this->applyTerminalUpdate($row, ConnectorConnectionCheckStatus::Failed, $classification, $durationMs);
                $this->applyVendorProjection($account, $row, succeeded: false);

                return null;
            }

            $row->update(array_merge($classification, [
                'status' => ConnectorConnectionCheckStatus::Running,
                'duration_ms' => $durationMs,
                'next_attempt_at' => now()->addSeconds($delay),
            ]));

            return $delay;
        });
    }

    public function writeLifecycleFailure(
        string $workspaceId,
        string $connectorAccountId,
        string $connectionCheckId,
        ConnectorConnectionCheckLifecycleErrorCode $code,
    ): void {
        DB::transaction(function () use ($workspaceId, $connectorAccountId, $connectionCheckId, $code): void {
            $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $connectionCheckId);

            if ($row === null || $row->isTerminal()) {
                return;
            }

            $row->update([
                'status' => ConnectorConnectionCheckStatus::Failed,
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
        string $connectionCheckId,
    ): void {
        DB::transaction(function () use ($workspaceId, $connectorAccountId, $connectionCheckId): void {
            $account = $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $connectionCheckId);

            if ($row === null || $row->isTerminal()) {
                return;
            }

            if (! $row->hasVendorClassification()) {
                $this->writeLifecycleFailureInTransaction($row, ConnectorConnectionCheckLifecycleErrorCode::JobFailed);

                return;
            }

            $row->update([
                'status' => ConnectorConnectionCheckStatus::Failed,
                'finished_at' => now(),
                'next_attempt_at' => null,
            ]);

            $this->applyVendorProjection($account, $row, succeeded: false);
        });
    }

    public function terminalizeAttemptsExhausted(
        string $workspaceId,
        string $connectorAccountId,
        string $connectionCheckId,
    ): void {
        DB::transaction(function () use ($workspaceId, $connectorAccountId, $connectionCheckId): void {
            $account = $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $connectionCheckId);

            if ($row === null || $row->isTerminal()) {
                return;
            }

            if ($row->hasVendorClassification()) {
                $row->update([
                    'status' => ConnectorConnectionCheckStatus::Failed,
                    'finished_at' => now(),
                    'next_attempt_at' => null,
                ]);
                $this->applyVendorProjection($account, $row, succeeded: false);

                return;
            }

            $this->writeLifecycleFailureInTransaction(
                $row,
                ConnectorConnectionCheckLifecycleErrorCode::AttemptsExhaustedWithoutResult,
            );
        });
    }

    public function terminalizeAccountDisabledBeforeExecution(
        string $workspaceId,
        string $connectorAccountId,
        string $connectionCheckId,
    ): void {
        DB::transaction(function () use ($workspaceId, $connectorAccountId, $connectionCheckId): void {
            $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $connectionCheckId);

            if ($row === null || $row->isTerminal()) {
                return;
            }

            $this->writeLifecycleFailureInTransaction(
                $row,
                ConnectorConnectionCheckLifecycleErrorCode::AccountDisabledBeforeExecution,
            );
        });
    }

    /**
     * @return array{reserved: bool, releaseDelaySeconds: ?int}
     */
    public function reserveExecutionSlot(
        string $workspaceId,
        string $connectorAccountId,
        string $connectionCheckId,
    ): array {
        return DB::transaction(function () use ($workspaceId, $connectorAccountId, $connectionCheckId): array {
            $account = $this->lockAccount($workspaceId, $connectorAccountId);
            $row = $this->lockHistoryRow($workspaceId, $connectorAccountId, $connectionCheckId);

            if ($row === null || $row->isTerminal()) {
                return ['reserved' => false, 'releaseDelaySeconds' => null];
            }

            if (! $account->is_enabled) {
                $this->writeLifecycleFailureInTransaction(
                    $row,
                    ConnectorConnectionCheckLifecycleErrorCode::AccountDisabledBeforeExecution,
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
                        'status' => ConnectorConnectionCheckStatus::Failed,
                        'finished_at' => now(),
                        'next_attempt_at' => null,
                    ]);
                    $this->applyVendorProjection($account, $row, succeeded: false);
                } else {
                    $this->writeLifecycleFailureInTransaction(
                        $row,
                        ConnectorConnectionCheckLifecycleErrorCode::AttemptsExhaustedWithoutResult,
                    );
                }

                return ['reserved' => false, 'releaseDelaySeconds' => null];
            }

            $updates = [
                'execution_attempts' => $row->execution_attempts + 1,
                'next_attempt_at' => null,
            ];

            if ($row->status !== ConnectorConnectionCheckStatus::Running) {
                $updates['status'] = ConnectorConnectionCheckStatus::Running;
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
        ConnectorConnectionCheck $row,
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
        ConnectorConnectionCheck $row,
    ): void {
        if ($row->hasVendorClassification()) {
            $row->update([
                'status' => ConnectorConnectionCheckStatus::Failed,
                'finished_at' => now(),
                'next_attempt_at' => null,
            ]);
            $this->applyVendorProjection($account, $row, succeeded: false);

            return;
        }

        $lifecycleCode = $row->status === ConnectorConnectionCheckStatus::Queued
            ? ConnectorConnectionCheckLifecycleErrorCode::DispatchFailed
            : ConnectorConnectionCheckLifecycleErrorCode::JobFailed;

        $this->writeLifecycleFailureInTransaction($row, $lifecycleCode);
    }

    public function isStale(ConnectorConnectionCheck $row): bool
    {
        if ($row->retry_until_at === null) {
            return false;
        }

        if ($row->status === ConnectorConnectionCheckStatus::Queued) {
            return $row->retry_until_at->isPast();
        }

        if ($row->status === ConnectorConnectionCheckStatus::Running) {
            return $row->retry_until_at->copy()->addSeconds(120)->isPast();
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
        string $connectionCheckId,
    ): ?ConnectorConnectionCheck {
        return ConnectorConnectionCheck::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('id', $connectionCheckId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function classificationFromResult(ConnectorConnectionCheckResult $result): array
    {
        return [
            'cause_category' => $result->cause(),
            'actionability' => $result->actionability(),
            'error_code' => $result->errorCode?->value,
            'http_status' => $result->httpStatus,
            'user_message_key' => $result->messageKey(),
            'safe_message_parameters' => $result->safeMessageParameters(),
            'technical_summary' => $result->technicalSummary(),
            'vendor_request_id' => $result->vendorRequestId,
        ];
    }

    /**
     * @param  array<string, mixed>  $classification
     */
    private function applyTerminalUpdate(
        ConnectorConnectionCheck $row,
        ConnectorConnectionCheckStatus $status,
        array $classification,
        int $durationMs,
    ): void {
        $row->update(array_merge($classification, [
            'status' => $status,
            'duration_ms' => $durationMs,
            'finished_at' => now(),
            'next_attempt_at' => null,
        ]));
    }

    private function writeLifecycleFailureInTransaction(
        ConnectorConnectionCheck $row,
        ConnectorConnectionCheckLifecycleErrorCode $code,
    ): void {
        $row->update([
            'status' => ConnectorConnectionCheckStatus::Failed,
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

    private function applyVendorProjection(
        ConnectorAccount $account,
        ConnectorConnectionCheck $row,
        bool $succeeded,
    ): void {
        if (! $account->is_enabled) {
            return;
        }

        $finishedAt = $row->finished_at ?? now();

        if ($succeeded) {
            $account->update([
                'connection_status' => ConnectorAccountConnectionStatus::Connected,
                'last_checked_at' => $finishedAt,
                'last_successful_check_at' => $finishedAt,
                'last_error_cause' => null,
                'last_error_actionability' => null,
                'last_error_message_key' => null,
                'last_error_at' => null,
            ]);

            return;
        }

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
            'last_checked_at' => $finishedAt,
            'last_error_cause' => $row->cause_category,
            'last_error_actionability' => $row->actionability,
            'last_error_message_key' => $row->user_message_key,
            'last_error_at' => $finishedAt,
        ]);
    }

    private function computeRetryDelay(ConnectorConnectionCheckResult $result, int $executionAttempts): int
    {
        if (
            $result->errorCode === ConnectorConnectionCheckErrorCode::AdobeRateLimited
            && $result->httpStatus === 429
            && $result->retryAfterSeconds !== null
        ) {
            return $result->retryAfterSeconds;
        }

        return $executionAttempts === 1 ? 30 : 120;
    }
}
