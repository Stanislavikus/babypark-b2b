<?php

namespace App\Services\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Jobs\Connectors\SyncLiveRunJob;
use App\Models\ConnectorAccount;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\Exceptions\SyncLiveAdmissionException;
use App\Support\Sync\Exceptions\SyncRuntimeTimingConfigurationException;
use App\Support\Sync\Preview\SyncPreviewConfigurationReadinessResolver;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SyncLiveAdmissionService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly ConnectorSyncSupportResolver $syncSupportResolver,
        private readonly SyncConfigurationMutationCoordinator $mutationCoordinator,
        private readonly SyncPreviewConfigurationSnapshotBuilder $snapshotBuilder,
        private readonly SyncPreviewConfigurationReadinessResolver $readinessResolver,
        private readonly SyncRunActiveRecoveryService $activeRecoveryService,
        private readonly SyncRuntimeTimingResolver $timingResolver,
    ) {}

    public function admit(
        User $actor,
        ConnectorAccount $account,
        string $syncConfigurationId,
    ): SyncRun {
        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new \RuntimeException('Sync live admission must not run inside a nested transaction.');
        }

        $run = null;

        DB::transaction(function () use (
            $actor,
            $account,
            $syncConfigurationId,
            &$run,
        ): void {
            try {
                $admissionTiming = $this->timingResolver->resolveAdmissionTiming();
            } catch (SyncRuntimeTimingConfigurationException) {
                throw SyncLiveAdmissionException::unsafeTimingConfiguration();
            }

            $lockedWorkspace = Workspace::query()
                ->whereKey($account->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::RUN_SYNC_LIVE)) {
                throw SyncLiveAdmissionException::notAuthorized();
            }

            $configuration = $this->mutationCoordinator->lockConfiguration($account, $syncConfigurationId);

            $freshAccount = ConnectorAccount::withoutWorkspaceScope()
                ->where('workspace_id', $account->workspace_id)
                ->where('id', $account->id)
                ->firstOrFail();

            if (! $freshAccount->is_enabled) {
                throw SyncLiveAdmissionException::accountNotEnabled($freshAccount->id);
            }

            if ($configuration->workspace_id !== $freshAccount->workspace_id || $configuration->connector_account_id !== $freshAccount->id) {
                throw SyncConfigurationNotFoundException::forId($syncConfigurationId);
            }

            if ($configuration->operational_state !== SyncConfigurationOperationalState::Enabled) {
                throw SyncLiveAdmissionException::configurationNotEnabled($configuration->id);
            }

            if ($configuration->data_domain !== SyncDataDomain::Products) {
                throw SyncLiveAdmissionException::operationNotSupported();
            }

            if (! $configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export)) {
                throw SyncLiveAdmissionException::operationNotEnabled(SyncSemanticOperation::Export->value);
            }

            if (! $this->syncSupportResolver->supports(
                $freshAccount,
                SyncDataDomain::Products,
                SyncSemanticOperation::Export,
                SyncRunMode::Live,
            )) {
                throw SyncLiveAdmissionException::operationNotSupported();
            }

            if (! $this->readinessResolver->resolve($freshAccount)->isReady($configuration)) {
                throw SyncLiveAdmissionException::attributeSetUnconfigured();
            }

            $this->activeRecoveryService->recoverStaleActiveRuns($configuration->id);

            $activeRunExists = SyncRun::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configuration->id)
                ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
                ->exists();

            if ($activeRunExists) {
                throw SyncLiveAdmissionException::activeRunExists($configuration->id);
            }

            $previewEvidenceExists = SyncRun::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configuration->id)
                ->where('mode', SyncRunMode::Preview)
                ->where('semantic_operation', SyncSemanticOperation::Export)
                ->where('status', SyncRunStatus::Completed)
                ->where('configuration_revision', $configuration->configuration_revision)
                ->exists();

            if (! $previewEvidenceExists) {
                throw SyncLiveAdmissionException::previewEvidenceMissing();
            }

            $snapshot = $this->snapshotBuilder->build($configuration, SyncSemanticOperation::Export);

            $run = SyncRun::withoutWorkspaceScope()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $configuration->workspace_id,
                'sync_configuration_id' => $configuration->id,
                'configuration_revision' => $configuration->configuration_revision,
                'mode' => SyncRunMode::Live,
                'semantic_operation' => SyncSemanticOperation::Export,
                'status' => SyncRunStatus::Queued,
                'initiated_by_user_id' => $actor->id,
                'configuration_snapshot' => $snapshot,
                'queued_abandon_after' => now()->addSeconds($admissionTiming->queuedUndispatchedGraceSeconds),
            ]);
        });

        if (! $run instanceof SyncRun) {
            throw new \RuntimeException('Live run was not created during admission.');
        }

        try {
            SyncLiveRunJob::dispatch(
                $account->workspace_id,
                $account->id,
                $run->id,
            );
        } catch (\Throwable $exception) {
            SyncRun::withoutWorkspaceScope()
                ->where('workspace_id', $account->workspace_id)
                ->where('id', $run->id)
                ->where('status', SyncRunStatus::Queued)
                ->update([
                    'status' => SyncRunStatus::Failed,
                    'completed_at' => now(),
                ]);

            throw SyncLiveAdmissionException::dispatchFailed(previous: $exception);
        }

        SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('id', $run->id)
            ->where('status', SyncRunStatus::Queued)
            ->update([
                'queue_dispatch_confirmed_at' => now(),
            ]);

        return $run->refresh();
    }
}
