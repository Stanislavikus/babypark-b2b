<?php

namespace App\Services\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Jobs\Connectors\SyncPreviewRunJob;
use App\Models\ConnectorAccount;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\Exceptions\SyncPreviewAdmissionException;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SyncPreviewAdmissionService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly ConnectorSyncSupportResolver $syncSupportResolver,
        private readonly SyncConfigurationMutationCoordinator $mutationCoordinator,
        private readonly SyncPreviewConfigurationSnapshotBuilder $snapshotBuilder,
    ) {}

    public function admit(
        User $actor,
        ConnectorAccount $account,
        string $syncConfigurationId,
        SyncSemanticOperation $semanticOperation,
    ): SyncRun {
        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new \RuntimeException('Sync preview admission must not run inside a nested transaction.');
        }

        $workspace = $account->workspace()->withoutGlobalScopes()->firstOrFail();

        if (! $this->authorization->allows($actor, $workspace, WorkspacePermissions::RUN_SYNC_PREVIEW)) {
            throw SyncPreviewAdmissionException::notAuthorized();
        }

        $run = null;

        DB::transaction(function () use (
            $actor,
            $account,
            $syncConfigurationId,
            $semanticOperation,
            &$run,
        ): void {
            $configuration = $this->mutationCoordinator->lockConfiguration($account, $syncConfigurationId);

            if ($configuration->workspace_id !== $account->workspace_id || $configuration->connector_account_id !== $account->id) {
                throw SyncConfigurationNotFoundException::forId($syncConfigurationId);
            }

            if ($configuration->operational_state !== SyncConfigurationOperationalState::Enabled) {
                throw SyncPreviewAdmissionException::configurationNotEnabled($configuration->id);
            }

            if (! $configuration->enabledOperationSet()->contains($semanticOperation)) {
                throw SyncPreviewAdmissionException::operationNotEnabled($semanticOperation->value);
            }

            if (! $this->syncSupportResolver->supports(
                $account,
                $configuration->data_domain,
                $semanticOperation,
                SyncRunMode::Preview,
            )) {
                throw SyncPreviewAdmissionException::operationNotSupported();
            }

            if ($configuration->connectorExecutionConfiguration()->attributeSetId() === null) {
                throw SyncPreviewAdmissionException::attributeSetUnconfigured();
            }

            $activeRunExists = SyncRun::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configuration->id)
                ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
                ->exists();

            if ($activeRunExists) {
                throw SyncPreviewAdmissionException::activeRunExists($configuration->id);
            }

            $snapshot = $this->snapshotBuilder->build($configuration, $semanticOperation);

            $run = SyncRun::withoutWorkspaceScope()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $configuration->workspace_id,
                'sync_configuration_id' => $configuration->id,
                'configuration_revision' => $configuration->configuration_revision,
                'mode' => SyncRunMode::Preview,
                'semantic_operation' => $semanticOperation,
                'status' => SyncRunStatus::Queued,
                'initiated_by_user_id' => $actor->id,
                'configuration_snapshot' => $snapshot,
            ]);
        });

        if (! $run instanceof SyncRun) {
            throw new \RuntimeException('Preview run was not created during admission.');
        }

        SyncPreviewRunJob::dispatch(
            $account->workspace_id,
            $account->id,
            $run->id,
        )->afterCommit();

        return $run->refresh();
    }
}
