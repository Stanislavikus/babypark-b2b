<?php

namespace App\Services\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncPreviewMerchantPageState;
use App\Enums\SyncPreviewOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use App\Support\Sync\Preview\Merchant\SyncPreviewMerchantReadModel;
use App\Support\Sync\Preview\Merchant\SyncPreviewMerchantResultSummary;
use App\Support\Sync\Preview\SyncPreviewConfigurationReadinessResolver;
use Illuminate\Auth\Access\AuthorizationException;

final class SyncPreviewMerchantReadService
{
    public function __construct(
        private readonly AdobeProductsExportPreviewAuthorizationService $authorizationService,
        private readonly ConnectorAccountLayerBSetupProjectionQuery $projectionQuery,
        private readonly SyncConfigurationLookupService $lookupService,
        private readonly ConnectorSyncSupportResolver $syncSupportResolver,
        private readonly SyncPreviewConfigurationReadinessResolver $readinessResolver,
    ) {}

    public function project(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
    ): SyncPreviewMerchantReadModel {
        if (! $this->authorizationService->isEligiblePreviewTarget($actor, $workspace, $connectorAccountId)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $projection = $this->projectionQuery->resolve($workspace->id, $connectorAccountId);
        $eligibilityProjection = $this->projectionQuery->resolveEligibility($workspace->id, $connectorAccountId);

        if ($projection === null || $eligibilityProjection === null) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $account = $this->accountReference($eligibilityProjection->id, $eligibilityProjection->workspaceId, $eligibilityProjection->authProfile);
        $configuration = $this->lookupService->findProductsDefaultContext($account);
        $canManageSetup = $this->authorizationService->canManageSetup($actor, $workspace);

        if (! $eligibilityProjection->isSetupUsable()) {
            return new SyncPreviewMerchantReadModel(
                pageState: SyncPreviewMerchantPageState::AccountUnavailable,
                platformName: $projection->platformName,
                accountName: $projection->accountName,
                accountSetupUsable: false,
                canManageSetup: $canManageSetup,
                canStartPreview: false,
                configurationChangedSinceRun: false,
                displayedRunId: null,
                configurationId: $configuration?->id,
                resultSummary: null,
                hasActiveRun: false,
            );
        }

        if ($configuration === null) {
            return new SyncPreviewMerchantReadModel(
                pageState: SyncPreviewMerchantPageState::ConfigurationAbsent,
                platformName: $projection->platformName,
                accountName: $projection->accountName,
                accountSetupUsable: true,
                canManageSetup: $canManageSetup,
                canStartPreview: false,
                configurationChangedSinceRun: false,
                displayedRunId: null,
                configurationId: null,
                resultSummary: null,
                hasActiveRun: false,
            );
        }

        if ($configuration->operational_state === SyncConfigurationOperationalState::Paused) {
            return $this->baseModel(
                SyncPreviewMerchantPageState::ConfigurationPaused,
                $projection,
                $configuration,
                $canManageSetup,
                null,
                null,
                false,
            );
        }

        if (! $configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export)) {
            return $this->baseModel(
                SyncPreviewMerchantPageState::ExportUnavailable,
                $projection,
                $configuration,
                $canManageSetup,
                null,
                null,
                false,
            );
        }

        if (! $this->syncSupportResolver->supports(
            $account,
            $configuration->data_domain,
            SyncSemanticOperation::Export,
            SyncRunMode::Preview,
        )) {
            return $this->baseModel(
                SyncPreviewMerchantPageState::ExportUnavailable,
                $projection,
                $configuration,
                $canManageSetup,
                null,
                null,
                false,
            );
        }

        $activeRun = $this->findActiveRun($workspace->id, $configuration->id);

        if ($activeRun !== null) {
            $pageState = $activeRun->status === SyncRunStatus::Running
                ? SyncPreviewMerchantPageState::Running
                : SyncPreviewMerchantPageState::Queued;

            return $this->baseModel(
                $pageState,
                $projection,
                $configuration,
                $canManageSetup,
                $activeRun,
                null,
                true,
            );
        }

        $latestRun = $this->findLatestRelevantRun($workspace->id, $configuration->id);

        if ($latestRun === null) {
            $canStart = $this->readinessResolver->resolve($account)->isReady($configuration);

            return $this->baseModel(
                SyncPreviewMerchantPageState::ReadyToPreview,
                $projection,
                $configuration,
                $canManageSetup,
                null,
                null,
                false,
                $canStart,
            );
        }

        if ($latestRun->status === SyncRunStatus::Failed) {
            return $this->baseModel(
                SyncPreviewMerchantPageState::Failed,
                $projection,
                $configuration,
                $canManageSetup,
                $latestRun,
                null,
                false,
                $this->canStartAfterTerminalRun($account, $configuration),
            );
        }

        return $this->baseModel(
            SyncPreviewMerchantPageState::Completed,
            $projection,
            $configuration,
            $canManageSetup,
            $latestRun,
            $this->buildResultSummary($latestRun),
            false,
            $this->canStartAfterTerminalRun($account, $configuration),
        );
    }

    private function baseModel(
        SyncPreviewMerchantPageState $pageState,
        object $projection,
        SyncConfiguration $configuration,
        bool $canManageSetup,
        ?SyncRun $displayedRun,
        ?SyncPreviewMerchantResultSummary $resultSummary,
        bool $hasActiveRun,
        bool $canStartPreview = false,
    ): SyncPreviewMerchantReadModel {
        $configurationChanged = $displayedRun !== null
            && $displayedRun->configuration_revision !== $configuration->configuration_revision;

        return new SyncPreviewMerchantReadModel(
            pageState: $pageState,
            platformName: $projection->platformName,
            accountName: $projection->accountName,
            accountSetupUsable: $projection->setupUsable,
            canManageSetup: $canManageSetup,
            canStartPreview: $canStartPreview,
            configurationChangedSinceRun: $configurationChanged,
            displayedRunId: $displayedRun?->id,
            configurationId: $configuration->id,
            resultSummary: $resultSummary,
            hasActiveRun: $hasActiveRun,
        );
    }

    private function buildResultSummary(SyncRun $run): SyncPreviewMerchantResultSummary
    {
        $counts = SyncRunItem::withoutWorkspaceScope()
            ->selectRaw('outcome, COUNT(*) as aggregate')
            ->where('workspace_id', $run->workspace_id)
            ->where('sync_run_id', $run->id)
            ->groupBy('outcome')
            ->pluck('aggregate', 'outcome');

        $ready = (int) ($counts[SyncPreviewOutcome::Ready->value] ?? 0);
        $warning = (int) ($counts[SyncPreviewOutcome::Warning->value] ?? 0);
        $blocked = (int) ($counts[SyncPreviewOutcome::Blocked->value] ?? 0);

        return new SyncPreviewMerchantResultSummary(
            readyCount: $ready,
            warningCount: $warning,
            blockedCount: $blocked,
            needsAttentionCount: $warning + $blocked,
            completedAtLabel: $run->completed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
        );
    }

    private function findActiveRun(string $workspaceId, string $configurationId): ?SyncRun
    {
        return SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('sync_configuration_id', $configurationId)
            ->where('mode', SyncRunMode::Preview)
            ->where('semantic_operation', SyncSemanticOperation::Export)
            ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function findLatestRelevantRun(string $workspaceId, string $configurationId): ?SyncRun
    {
        return SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('sync_configuration_id', $configurationId)
            ->where('mode', SyncRunMode::Preview)
            ->where('semantic_operation', SyncSemanticOperation::Export)
            ->whereIn('status', [SyncRunStatus::Completed, SyncRunStatus::Failed])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function canStartAfterTerminalRun(ConnectorAccount $account, SyncConfiguration $configuration): bool
    {
        if ($configuration->operational_state !== SyncConfigurationOperationalState::Enabled) {
            return false;
        }

        if (! $configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export)) {
            return false;
        }

        return $this->readinessResolver->resolve($account)->isReady($configuration);
    }

    private function accountReference(string $id, string $workspaceId, string $authProfile): ConnectorAccount
    {
        $account = new ConnectorAccount;
        $account->forceFill([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'auth_profile' => $authProfile,
            'is_enabled' => true,
        ]);
        $account->exists = true;

        return $account;
    }
}
