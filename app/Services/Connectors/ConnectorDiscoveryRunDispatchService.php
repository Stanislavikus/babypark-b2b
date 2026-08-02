<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorCapability;
use App\Enums\ConnectorDiscoveryRunLifecycleErrorCode;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorDiscoveryRunTrigger;
use App\Jobs\Connectors\ConnectorDiscoveryRunJob;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSource;
use App\Models\User;
use App\Support\Connectors\ConnectorDiscoveryDispatchDecision;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\Exceptions\ConnectorAccountDisabledException;
use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;
use App\Support\Connectors\Exceptions\ConnectorDiscoveryManualTriggerDisabledException;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceResolutionException;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceResolutionReason;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ConnectorDiscoveryRunDispatchService
{
    public function __construct(
        private readonly ConnectorProfileRegistry $profileRegistry,
        private readonly ConnectorDiscoveryRunPersistence $persistence,
        private readonly ConnectorDiscoverySourceResolver $sourceResolver,
    ) {}

    public function executeManual(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
    ): string {
        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new \RuntimeException('executeManual must not run inside a nested transaction.');
        }

        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectorAccountId)
            ->first();

        if ($account === null) {
            throw new ConnectorAccountNotFoundException('Connector account was not found.');
        }

        Gate::forUser($actor)->authorize('runDiscovery', $account);

        if (! config('connectors.discovery.manual_trigger_enabled')) {
            throw new ConnectorDiscoveryManualTriggerDisabledException;
        }

        $this->profileRegistry->requireCapability(
            $account->auth_profile,
            ConnectorCapability::SchemaDiscovery,
        );

        $resolvedSource = $this->sourceResolver->resolve($account);

        if (! $account->is_enabled) {
            throw new ConnectorAccountDisabledException('Connector account is disabled.');
        }

        $lockKey = "connector-op:{$workspaceId}:{$connectorAccountId}:schema_discovery";

        $decision = Cache::lock($lockKey, 30)->block(5, function () use (
            $actor,
            $workspaceId,
            $connectorAccountId,
            $resolvedSource,
        ): ConnectorDiscoveryDispatchDecision {
            return DB::transaction(function () use (
                $actor,
                $workspaceId,
                $connectorAccountId,
                $resolvedSource,
            ): ConnectorDiscoveryDispatchDecision {
                $lockedAccount = ConnectorAccount::withoutWorkspaceScope()
                    ->where('workspace_id', $workspaceId)
                    ->where('id', $connectorAccountId)
                    ->lockForUpdate()
                    ->first();

                if ($lockedAccount === null) {
                    throw new ConnectorAccountNotFoundException('Connector account was not found.');
                }

                Gate::forUser($actor)->authorize('runDiscovery', $lockedAccount);

                if (! $lockedAccount->is_enabled) {
                    throw new ConnectorAccountDisabledException('Connector account is disabled.');
                }

                $this->profileRegistry->requireCapability(
                    $lockedAccount->auth_profile,
                    ConnectorCapability::SchemaDiscovery,
                );

                $reverifiedSource = ConnectorSchemaSource::query()->find($resolvedSource->id);

                if (
                    $reverifiedSource === null
                    || ! $this->sourceResolver->reverify($lockedAccount, $reverifiedSource)
                ) {
                    throw new ConnectorDiscoverySourceResolutionException(
                        ConnectorDiscoverySourceResolutionReason::Missing,
                        0,
                    );
                }

                $existingRow = ConnectorDiscoveryRun::withoutWorkspaceScope()
                    ->where('workspace_id', $workspaceId)
                    ->where('connector_account_id', $connectorAccountId)
                    ->where('connector_schema_source_id', $reverifiedSource->id)
                    ->whereIn('status', [
                        ConnectorDiscoveryRunStatus::Queued,
                        ConnectorDiscoveryRunStatus::Running,
                    ])
                    ->lockForUpdate()
                    ->first();

                if ($existingRow !== null) {
                    if ($this->persistence->isStale($existingRow)) {
                        $this->persistence->recoverStaleRow($lockedAccount, $existingRow);
                    } else {
                        return ConnectorDiscoveryDispatchDecision::existing($existingRow->id);
                    }
                }

                $retryUntilAt = now()->addMinutes(60);

                $newRow = ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
                    'workspace_id' => $workspaceId,
                    'connector_account_id' => $connectorAccountId,
                    'connector_schema_source_id' => $reverifiedSource->id,
                    'trigger' => ConnectorDiscoveryRunTrigger::Manual,
                    'initiated_by_user_id' => $actor->getKey(),
                    'status' => ConnectorDiscoveryRunStatus::Queued,
                    'execution_attempts' => 0,
                    'retry_until_at' => $retryUntilAt,
                    'next_attempt_at' => null,
                    'started_at' => null,
                ]);

                return ConnectorDiscoveryDispatchDecision::dispatch(
                    $newRow->id,
                    $retryUntilAt->getTimestamp(),
                );
            });
        });

        if ($decision->shouldDispatch) {
            try {
                ConnectorDiscoveryRunJob::dispatch(
                    $workspaceId,
                    $connectorAccountId,
                    $decision->discoveryRunId,
                    $decision->retryUntilTimestamp,
                )->afterCommit();
            } catch (\Throwable) {
                $this->persistence->writeLifecycleFailure(
                    $workspaceId,
                    $connectorAccountId,
                    $decision->discoveryRunId,
                    ConnectorDiscoveryRunLifecycleErrorCode::DispatchFailed,
                );
            }
        }

        return $decision->discoveryRunId;
    }
}
