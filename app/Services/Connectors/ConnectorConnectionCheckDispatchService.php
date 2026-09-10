<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorCapability;
use App\Enums\ConnectorConnectionCheckLifecycleErrorCode;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Jobs\Connectors\ConnectorConnectionCheckJob;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\User;
use App\Support\Connectors\ConnectionCheckDispatchDecision;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\Exceptions\ConnectorAccountDisabledException;
use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;
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
            throw new \RuntimeException('executeManual must not run inside a nested transaction.');
        }

        $lockKey = "connector-op:{$workspaceId}:{$connectorAccountId}:connection_check";

        $decision = Cache::lock($lockKey, 30)->block(5, function () use (
            $actor,
            $workspaceId,
            $connectorAccountId,
        ): ConnectionCheckDispatchDecision {
            return DB::transaction(function () use (
                $actor,
                $workspaceId,
                $connectorAccountId,
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
                    'trigger' => ConnectorConnectionCheckTrigger::Manual,
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
}
