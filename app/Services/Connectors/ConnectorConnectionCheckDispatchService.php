<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorCapability;
use App\Enums\ConnectorConnectionCheckLifecycleErrorCode;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Enums\ConnectorErrorActionability;
use App\Jobs\Connectors\ConnectorConnectionCheckJob;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\User;
use App\Support\Connectors\ConnectionCheckDispatchDecision;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\Exceptions\ConnectorAccountDisabledException;
use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;
use App\Support\Connectors\Exceptions\ConnectorProfileNotFoundException;
use App\Support\Connectors\Exceptions\DisabledConnectorProfileException;
use App\Support\Connectors\Exceptions\UnsupportedConnectorCapabilityException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ConnectorConnectionCheckDispatchService
{
    public function __construct(
        private readonly ConnectorProfileRegistry $profileRegistry,
        private readonly ConnectorConnectionCheckPersistence $persistence,
    ) {}

    public function executeManual(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
    ): string {
        return $this->execute(
            $actor,
            $workspaceId,
            $connectorAccountId,
            ConnectorConnectionCheckTrigger::Manual,
        );
    }

    public function executeFirstConnect(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
    ): string {
        return $this->execute(
            $actor,
            $workspaceId,
            $connectorAccountId,
            ConnectorConnectionCheckTrigger::FirstConnect,
        );
    }

    public function executeRecovery(
        string $workspaceId,
        string $connectorAccountId,
        string $sourceCheckId,
    ): ?string {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectorAccountId)
            ->first();

        if ($account === null || ! $this->recoverySourceStillCurrent($account, $sourceCheckId)) {
            return null;
        }

        try {
            $this->profileRegistry->requireCapability(
                $account->auth_profile,
                ConnectorCapability::ConnectionCheck,
            );
        } catch (ConnectorProfileNotFoundException|DisabledConnectorProfileException|UnsupportedConnectorCapabilityException) {
            return null;
        }

        $lockKey = "connector-op:{$workspaceId}:{$connectorAccountId}:connection_check";

        $decision = Cache::lock($lockKey, 30)->block(5, function () use (
            $workspaceId,
            $connectorAccountId,
            $sourceCheckId,
        ): ?ConnectionCheckDispatchDecision {
            return DB::transaction(function () use (
                $workspaceId,
                $connectorAccountId,
                $sourceCheckId,
            ): ?ConnectionCheckDispatchDecision {
                $lockedAccount = ConnectorAccount::withoutWorkspaceScope()
                    ->where('workspace_id', $workspaceId)
                    ->where('id', $connectorAccountId)
                    ->lockForUpdate()
                    ->first();

                if ($lockedAccount === null || ! $this->recoverySourceStillCurrent($lockedAccount, $sourceCheckId, lockSource: true)) {
                    return null;
                }

                $existingRow = ConnectorConnectionCheck::withoutWorkspaceScope()
                    ->where('workspace_id', $workspaceId)
                    ->where('connector_account_id', $connectorAccountId)
                    ->whereIn('status', [
                        ConnectorConnectionCheckStatus::Queued,
                        ConnectorConnectionCheckStatus::Running,
                    ])
                    ->lockForUpdate()
                    ->first();

                if ($existingRow !== null) {
                    return new ConnectionCheckDispatchDecision(
                        connectionCheckId: $existingRow->id,
                        shouldDispatch: false,
                        retryUntilTimestamp: null,
                    );
                }

                $retryUntilAt = now()->addMinutes(15);
                $newRow = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
                    'workspace_id' => $workspaceId,
                    'connector_account_id' => $connectorAccountId,
                    'trigger' => ConnectorConnectionCheckTrigger::Scheduled,
                    'initiated_by_user_id' => null,
                    'status' => ConnectorConnectionCheckStatus::Queued,
                    'execution_attempts' => 0,
                    'retry_until_at' => $retryUntilAt,
                    'next_attempt_at' => null,
                    'started_at' => null,
                ]);

                return new ConnectionCheckDispatchDecision(
                    connectionCheckId: $newRow->id,
                    shouldDispatch: true,
                    retryUntilTimestamp: $retryUntilAt->getTimestamp(),
                );
            });
        });

        if ($decision === null) {
            return null;
        }

        if ($decision->shouldDispatch) {
            try {
                ConnectorConnectionCheckJob::dispatch(
                    $workspaceId,
                    $connectorAccountId,
                    $decision->connectionCheckId,
                    $decision->retryUntilTimestamp,
                )->afterCommit();
            } catch (\Throwable) {
                $this->persistence->writeLifecycleFailure(
                    $workspaceId,
                    $connectorAccountId,
                    $decision->connectionCheckId,
                    ConnectorConnectionCheckLifecycleErrorCode::DispatchFailed,
                );
            }
        }

        return $decision->connectionCheckId;
    }

    private function execute(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
        ConnectorConnectionCheckTrigger $trigger,
    ): string {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectorAccountId)
            ->first();

        if ($account === null) {
            throw new ConnectorAccountNotFoundException('Connector account was not found.');
        }

        Gate::forUser($actor)->authorize('runConnectionCheck', $account);

        if (! $account->is_enabled) {
            throw new ConnectorAccountDisabledException('Connector account is disabled.');
        }

        $this->profileRegistry->requireCapability(
            $account->auth_profile,
            ConnectorCapability::ConnectionCheck,
        );

        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new \RuntimeException('Connection-check dispatch must not run inside a nested transaction.');
        }

        $lockKey = "connector-op:{$workspaceId}:{$connectorAccountId}:connection_check";

        $decision = Cache::lock($lockKey, 30)->block(5, function () use (
            $actor,
            $workspaceId,
            $connectorAccountId,
            $trigger,
        ): ConnectionCheckDispatchDecision {
            return DB::transaction(function () use (
                $actor,
                $workspaceId,
                $connectorAccountId,
                $trigger,
            ): ConnectionCheckDispatchDecision {
                $lockedAccount = ConnectorAccount::withoutWorkspaceScope()
                    ->where('workspace_id', $workspaceId)
                    ->where('id', $connectorAccountId)
                    ->lockForUpdate()
                    ->first();

                if ($lockedAccount === null) {
                    throw new ConnectorAccountNotFoundException('Connector account was not found.');
                }

                Gate::forUser($actor)->authorize('runConnectionCheck', $lockedAccount);

                if (! $lockedAccount->is_enabled) {
                    throw new ConnectorAccountDisabledException('Connector account is disabled.');
                }

                $this->profileRegistry->requireCapability(
                    $lockedAccount->auth_profile,
                    ConnectorCapability::ConnectionCheck,
                );

                $existingRow = ConnectorConnectionCheck::withoutWorkspaceScope()
                    ->where('workspace_id', $workspaceId)
                    ->where('connector_account_id', $connectorAccountId)
                    ->whereIn('status', [
                        ConnectorConnectionCheckStatus::Queued,
                        ConnectorConnectionCheckStatus::Running,
                    ])
                    ->lockForUpdate()
                    ->first();

                if ($existingRow !== null) {
                    if ($this->persistence->isStale($existingRow)) {
                        $this->persistence->recoverStaleRow($lockedAccount, $existingRow);
                    } else {
                        return new ConnectionCheckDispatchDecision(
                            connectionCheckId: $existingRow->id,
                            shouldDispatch: false,
                            retryUntilTimestamp: null,
                        );
                    }
                }

                $retryUntilAt = now()->addMinutes(15);

                $newRow = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
                    'workspace_id' => $workspaceId,
                    'connector_account_id' => $connectorAccountId,
                    'trigger' => $trigger,
                    'initiated_by_user_id' => $actor->getKey(),
                    'status' => ConnectorConnectionCheckStatus::Queued,
                    'execution_attempts' => 0,
                    'retry_until_at' => $retryUntilAt,
                    'next_attempt_at' => null,
                    'started_at' => null,
                ]);

                return new ConnectionCheckDispatchDecision(
                    connectionCheckId: $newRow->id,
                    shouldDispatch: true,
                    retryUntilTimestamp: $retryUntilAt->getTimestamp(),
                );
            });
        });

        if ($decision->shouldDispatch) {
            try {
                ConnectorConnectionCheckJob::dispatch(
                    $workspaceId,
                    $connectorAccountId,
                    $decision->connectionCheckId,
                    $decision->retryUntilTimestamp,
                )->afterCommit();
            } catch (\Throwable) {
                $this->persistence->writeLifecycleFailure(
                    $workspaceId,
                    $connectorAccountId,
                    $decision->connectionCheckId,
                    ConnectorConnectionCheckLifecycleErrorCode::DispatchFailed,
                );
            }
        }

        return $decision->connectionCheckId;
    }

    private function recoverySourceStillCurrent(
        ConnectorAccount $account,
        string $sourceCheckId,
        bool $lockSource = false,
    ): bool {
        if (
            ! $account->is_enabled
            || $account->connection_status !== ConnectorAccountConnectionStatus::TemporarilyUnavailable
            || $account->last_error_actionability !== ConnectorErrorActionability::AutomaticRetry
            || $account->last_error_at === null
        ) {
            return false;
        }

        $query = ConnectorConnectionCheck::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('id', $sourceCheckId);

        if ($lockSource) {
            $query->lockForUpdate();
        }

        $source = $query->first();

        return $source !== null
            && $source->status === ConnectorConnectionCheckStatus::Failed
            && $source->actionability === ConnectorErrorActionability::AutomaticRetry
            && $source->finished_at !== null
            && $account->last_error_at->equalTo($source->finished_at);
    }
}
