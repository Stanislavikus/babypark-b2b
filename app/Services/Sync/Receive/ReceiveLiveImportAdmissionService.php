<?php

namespace App\Services\Sync\Receive;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\SyncConfigurationMutationCoordinator;
use App\Services\Sync\SyncPreviewConfigurationSnapshotBuilder;
use App\Services\Sync\SyncRunActiveRecoveryService;
use App\Services\Sync\SyncRuntimeTimingResolver;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\Exceptions\SyncLiveAdmissionException;
use App\Support\Sync\Exceptions\SyncRuntimeTimingConfigurationException;
use App\Support\Sync\Receive\ReceiveProposal;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReceiveLiveImportAdmissionService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly ConnectorSyncSupportResolver $syncSupportResolver,
        private readonly SyncConfigurationMutationCoordinator $mutationCoordinator,
        private readonly SyncPreviewConfigurationSnapshotBuilder $snapshotBuilder,
        private readonly SyncRunActiveRecoveryService $activeRecoveryService,
        private readonly SyncRuntimeTimingResolver $timingResolver,
    ) {}

    public function admit(
        User $actor,
        ConnectorAccount $account,
        ReceiveProposal $proposal,
        string $productId,
    ): SyncRun {
        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new \RuntimeException('Receive live import admission must not run inside a nested transaction.');
        }

        try {
            $executionTiming = $this->timingResolver->resolveExecutionTiming();
        } catch (SyncRuntimeTimingConfigurationException) {
            throw SyncLiveAdmissionException::unsafeTimingConfiguration();
        }

        $run = null;

        DB::transaction(function () use ($actor, $account, $proposal, $productId, $executionTiming, &$run): void {
            $lockedWorkspace = Workspace::query()
                ->whereKey($account->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::RUN_SYNC_LIVE)) {
                throw SyncLiveAdmissionException::notAuthorized();
            }

            $configuration = $this->mutationCoordinator->lockConfiguration($account, $proposal->syncConfigurationId);

            $freshAccount = ConnectorAccount::withoutWorkspaceScope()
                ->where('workspace_id', $account->workspace_id)
                ->where('id', $account->id)
                ->firstOrFail();

            if (! $freshAccount->is_enabled) {
                throw SyncLiveAdmissionException::accountNotEnabled($freshAccount->id);
            }

            if (
                $proposal->workspaceId !== $freshAccount->workspace_id
                || $proposal->connectorAccountId !== $freshAccount->id
                || $configuration->workspace_id !== $freshAccount->workspace_id
                || $configuration->connector_account_id !== $freshAccount->id
            ) {
                throw SyncConfigurationNotFoundException::forId($proposal->syncConfigurationId);
            }

            if ($configuration->operational_state !== SyncConfigurationOperationalState::Enabled) {
                throw SyncLiveAdmissionException::configurationNotEnabled($configuration->id);
            }

            if ($configuration->data_domain !== SyncDataDomain::Products) {
                throw SyncLiveAdmissionException::operationNotSupported();
            }

            if (! $configuration->enabledOperationSet()->contains(SyncSemanticOperation::Import)) {
                throw SyncLiveAdmissionException::operationNotEnabled(SyncSemanticOperation::Import->value);
            }

            if ((string) $configuration->configuration_revision !== $proposal->configurationRevision) {
                throw SyncLiveAdmissionException::configurationRevisionChanged();
            }

            if (! $this->syncSupportResolver->supports(
                $freshAccount,
                SyncDataDomain::Products,
                SyncSemanticOperation::Import,
                SyncRunMode::Live,
            )) {
                throw SyncLiveAdmissionException::operationNotSupported();
            }

            $this->activeRecoveryService->recoverStaleActiveRuns($configuration->id);

            $activeRunExists = SyncRun::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configuration->id)
                ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
                ->exists();

            if ($activeRunExists) {
                throw SyncLiveAdmissionException::activeRunExists($configuration->id);
            }

            $snapshot = $this->snapshotBuilder->build($configuration, SyncSemanticOperation::Import);
            $snapshot['version'] = 'platform.sync-run-input.v2';
            $snapshot['execution_target'] = [
                'mode' => 'explicit_product',
                'product_id' => $productId,
            ];

            $startedAt = now();
            $lease = $executionTiming->leaseTimestampsFrom($startedAt);

            $run = SyncRun::withoutWorkspaceScope()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $configuration->workspace_id,
                'sync_configuration_id' => $configuration->id,
                'configuration_revision' => $configuration->configuration_revision,
                'mode' => SyncRunMode::Live,
                'semantic_operation' => SyncSemanticOperation::Import,
                'status' => SyncRunStatus::Running,
                'initiated_by_user_id' => $actor->id,
                'configuration_snapshot' => $snapshot,
                'started_at' => $startedAt,
                'writer_deadline_at' => $lease['writer_deadline_at'],
                'recoverable_after' => $lease['recoverable_after'],
            ]);
        });

        if (! $run instanceof SyncRun) {
            throw new \RuntimeException('Receive live import run was not created during admission.');
        }

        return $run->refresh();
    }
}
