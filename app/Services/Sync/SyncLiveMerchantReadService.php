<?php

namespace App\Services\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncLiveMerchantPageState;
use App\Enums\SyncLiveOutcome;
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
use App\Support\Sync\Live\Merchant\SyncLiveAdmissionReadiness;
use App\Support\Sync\Live\Merchant\SyncLiveMerchantReadModel;
use App\Support\Sync\Live\Merchant\SyncLiveMerchantResultSummary;
use App\Support\Sync\Preview\SyncPreviewConfigurationReadinessResolver;
use Illuminate\Auth\Access\AuthorizationException;

final class SyncLiveMerchantReadService
{
    public function __construct(
        private readonly AdobeProductsExportLiveAuthorizationService $authorizationService,
        private readonly ConnectorAccountLayerBSetupProjectionQuery $projectionQuery,
        private readonly SyncConfigurationLookupService $lookupService,
        private readonly ConnectorSyncSupportResolver $syncSupportResolver,
        private readonly SyncPreviewConfigurationReadinessResolver $readinessResolver,
    ) {}

    public function project(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
    ): SyncLiveMerchantReadModel {
        if (! $this->authorizationService->isEligibleLiveTarget($actor, $workspace, $connectorAccountId)) {
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
        $liveSupportEnabled = $configuration !== null && $this->syncSupportResolver->supports(
            $account,
            $configuration->data_domain,
            SyncSemanticOperation::Export,
            SyncRunMode::Live,
        );

        if (! $eligibilityProjection->isSetupUsable()) {
            return $this->baseModel(
                pageState: SyncLiveMerchantPageState::AccountUnavailable,
                projection: $projection,
                configuration: $configuration,
                canManageSetup: $canManageSetup,
                displayedRun: null,
                resultSummary: null,
                hasActiveRun: false,
                liveSupportEnabled: $liveSupportEnabled,
                hasPreviewEvidence: false,
                configurationReady: false,
                activeRunBlocking: false,
            );
        }

        if ($configuration === null) {
            return $this->baseModel(
                pageState: SyncLiveMerchantPageState::ConfigurationAbsent,
                projection: $projection,
                configuration: null,
                canManageSetup: $canManageSetup,
                displayedRun: null,
                resultSummary: null,
                hasActiveRun: false,
                liveSupportEnabled: false,
                hasPreviewEvidence: false,
                configurationReady: false,
                activeRunBlocking: false,
            );
        }

        if ($configuration->operational_state === SyncConfigurationOperationalState::Paused) {
            return $this->baseModel(
                pageState: SyncLiveMerchantPageState::ConfigurationPaused,
                projection: $projection,
                configuration: $configuration,
                canManageSetup: $canManageSetup,
                displayedRun: null,
                resultSummary: null,
                hasActiveRun: false,
                liveSupportEnabled: $liveSupportEnabled,
                hasPreviewEvidence: $this->hasPreviewEvidence($configuration),
                configurationReady: false,
                activeRunBlocking: false,
            );
        }

        if (! $configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export)) {
            return $this->baseModel(
                pageState: SyncLiveMerchantPageState::ExportUnavailable,
                projection: $projection,
                configuration: $configuration,
                canManageSetup: $canManageSetup,
                displayedRun: null,
                resultSummary: null,
                hasActiveRun: false,
                liveSupportEnabled: $liveSupportEnabled,
                hasPreviewEvidence: $this->hasPreviewEvidence($configuration),
                configurationReady: false,
                activeRunBlocking: false,
            );
        }

        $configurationReady = $this->configurationReady($account, $configuration);
        $hasPreviewEvidence = $this->hasPreviewEvidence($configuration);
        $activeRunBlocking = $this->hasActiveRun($configuration->id);

        $activeLiveRun = $this->findActiveLiveRun($workspace->id, $configuration->id);

        if ($activeLiveRun !== null) {
            $pageState = $activeLiveRun->status === SyncRunStatus::Running
                ? SyncLiveMerchantPageState::Running
                : SyncLiveMerchantPageState::Queued;

            return $this->baseModel(
                pageState: $pageState,
                projection: $projection,
                configuration: $configuration,
                canManageSetup: $canManageSetup,
                displayedRun: $activeLiveRun,
                resultSummary: null,
                hasActiveRun: true,
                liveSupportEnabled: $liveSupportEnabled,
                hasPreviewEvidence: $hasPreviewEvidence,
                configurationReady: $configurationReady,
                activeRunBlocking: true,
                processedProductCount: $this->countProcessedProducts($activeLiveRun),
            );
        }

        if ($activeRunBlocking) {
            return $this->baseModel(
                pageState: SyncLiveMerchantPageState::ActiveRunBlocking,
                projection: $projection,
                configuration: $configuration,
                canManageSetup: $canManageSetup,
                displayedRun: null,
                resultSummary: null,
                hasActiveRun: false,
                liveSupportEnabled: $liveSupportEnabled,
                hasPreviewEvidence: $hasPreviewEvidence,
                configurationReady: $configurationReady,
                activeRunBlocking: true,
            );
        }

        $latestLiveRun = $this->findLatestRelevantLiveRun($workspace->id, $configuration->id);

        if ($latestLiveRun !== null && $latestLiveRun->status === SyncRunStatus::Failed) {
            return $this->baseModel(
                pageState: SyncLiveMerchantPageState::Failed,
                projection: $projection,
                configuration: $configuration,
                canManageSetup: $canManageSetup,
                displayedRun: $latestLiveRun,
                resultSummary: null,
                hasActiveRun: false,
                liveSupportEnabled: $liveSupportEnabled,
                hasPreviewEvidence: $hasPreviewEvidence,
                configurationReady: $configurationReady,
                activeRunBlocking: false,
                canStartAfterTerminalRun: $this->canStartAfterTerminalRun(
                    $account,
                    $configuration,
                    $liveSupportEnabled,
                    $hasPreviewEvidence,
                ),
            );
        }

        if ($latestLiveRun !== null && $latestLiveRun->status === SyncRunStatus::Completed) {
            return $this->baseModel(
                pageState: SyncLiveMerchantPageState::Completed,
                projection: $projection,
                configuration: $configuration,
                canManageSetup: $canManageSetup,
                displayedRun: $latestLiveRun,
                resultSummary: $this->buildResultSummary($latestLiveRun),
                hasActiveRun: false,
                liveSupportEnabled: $liveSupportEnabled,
                hasPreviewEvidence: $hasPreviewEvidence,
                configurationReady: $configurationReady,
                activeRunBlocking: false,
                canStartAfterTerminalRun: $this->canStartAfterTerminalRun(
                    $account,
                    $configuration,
                    $liveSupportEnabled,
                    $hasPreviewEvidence,
                ),
            );
        }

        if (! $configurationReady) {
            return $this->baseModel(
                pageState: SyncLiveMerchantPageState::ConfigurationNotReady,
                projection: $projection,
                configuration: $configuration,
                canManageSetup: $canManageSetup,
                displayedRun: null,
                resultSummary: null,
                hasActiveRun: false,
                liveSupportEnabled: $liveSupportEnabled,
                hasPreviewEvidence: $hasPreviewEvidence,
                configurationReady: false,
                activeRunBlocking: false,
                currentSetupRequired: true,
            );
        }

        if (! $hasPreviewEvidence) {
            return $this->baseModel(
                pageState: SyncLiveMerchantPageState::PreviewPrerequisiteMissing,
                projection: $projection,
                configuration: $configuration,
                canManageSetup: $canManageSetup,
                displayedRun: null,
                resultSummary: null,
                hasActiveRun: false,
                liveSupportEnabled: $liveSupportEnabled,
                hasPreviewEvidence: false,
                configurationReady: true,
                activeRunBlocking: false,
            );
        }

        if (! $liveSupportEnabled) {
            return $this->baseModel(
                pageState: SyncLiveMerchantPageState::SupportNotEnabled,
                projection: $projection,
                configuration: $configuration,
                canManageSetup: $canManageSetup,
                displayedRun: null,
                resultSummary: null,
                hasActiveRun: false,
                liveSupportEnabled: false,
                hasPreviewEvidence: true,
                configurationReady: true,
                activeRunBlocking: false,
            );
        }

        return $this->baseModel(
            pageState: SyncLiveMerchantPageState::ReadyToTransfer,
            projection: $projection,
            configuration: $configuration,
            canManageSetup: $canManageSetup,
            displayedRun: null,
            resultSummary: null,
            hasActiveRun: false,
            liveSupportEnabled: true,
            hasPreviewEvidence: true,
            configurationReady: true,
            activeRunBlocking: false,
            canStartAfterTerminalRun: true,
        );
    }

    private function baseModel(
        SyncLiveMerchantPageState $pageState,
        object $projection,
        ?SyncConfiguration $configuration,
        bool $canManageSetup,
        ?SyncRun $displayedRun,
        ?SyncLiveMerchantResultSummary $resultSummary,
        bool $hasActiveRun,
        bool $liveSupportEnabled,
        bool $hasPreviewEvidence,
        bool $configurationReady,
        bool $activeRunBlocking,
        bool $canStartAfterTerminalRun = false,
        bool $currentSetupRequired = false,
        ?int $processedProductCount = null,
    ): SyncLiveMerchantReadModel {
        $configurationChanged = $displayedRun !== null
            && $configuration !== null
            && $displayedRun->configuration_revision !== $configuration->configuration_revision;

        $admissionReadiness = new SyncLiveAdmissionReadiness(
            hasLivePermission: true,
            liveSupportEnabled: $liveSupportEnabled,
            configurationReady: $configurationReady,
            hasPreviewEvidence: $hasPreviewEvidence,
            activeRunBlocking: $activeRunBlocking,
            canStartLive: $canStartAfterTerminalRun,
        );

        return new SyncLiveMerchantReadModel(
            pageState: $pageState,
            canManageSetup: $canManageSetup,
            canStartLive: $canStartAfterTerminalRun,
            configurationChangedSinceRun: $configurationChanged,
            displayedRunId: $displayedRun?->id,
            configurationId: $configuration?->id,
            resultSummary: $resultSummary,
            hasActiveRun: $hasActiveRun,
            currentSetupRequired: $currentSetupRequired,
            hasPreviewEvidence: $hasPreviewEvidence,
            admissionReadiness: $admissionReadiness,
            processedProductCount: $processedProductCount,
        );
    }

    private function configurationReady(ConnectorAccount $account, SyncConfiguration $configuration): bool
    {
        if ($configuration->operational_state !== SyncConfigurationOperationalState::Enabled) {
            return false;
        }

        if (! $configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export)) {
            return false;
        }

        return $this->readinessResolver->resolve($account)->isReady($configuration);
    }

    private function canStartAfterTerminalRun(
        ConnectorAccount $account,
        SyncConfiguration $configuration,
        bool $liveSupportEnabled,
        bool $hasPreviewEvidence,
    ): bool {
        if (! $liveSupportEnabled || ! $hasPreviewEvidence) {
            return false;
        }

        return $this->configurationReady($account, $configuration);
    }

    private function hasPreviewEvidence(SyncConfiguration $configuration): bool
    {
        return SyncRun::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('mode', SyncRunMode::Preview)
            ->where('semantic_operation', SyncSemanticOperation::Export)
            ->where('status', SyncRunStatus::Completed)
            ->where('configuration_revision', $configuration->configuration_revision)
            ->exists();
    }

    private function hasActiveRun(string $configurationId): bool
    {
        return SyncRun::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configurationId)
            ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
            ->exists();
    }

    private function findActiveLiveRun(string $workspaceId, string $configurationId): ?SyncRun
    {
        return SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('sync_configuration_id', $configurationId)
            ->where('mode', SyncRunMode::Live)
            ->where('semantic_operation', SyncSemanticOperation::Export)
            ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function findLatestRelevantLiveRun(string $workspaceId, string $configurationId): ?SyncRun
    {
        return SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('sync_configuration_id', $configurationId)
            ->where('mode', SyncRunMode::Live)
            ->where('semantic_operation', SyncSemanticOperation::Export)
            ->whereIn('status', [SyncRunStatus::Completed, SyncRunStatus::Failed])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function countProcessedProducts(SyncRun $run): int
    {
        return SyncRunItem::withoutWorkspaceScope()
            ->where('workspace_id', $run->workspace_id)
            ->where('sync_run_id', $run->id)
            ->count();
    }

    private function buildResultSummary(SyncRun $run): SyncLiveMerchantResultSummary
    {
        $counts = SyncRunItem::withoutWorkspaceScope()
            ->selectRaw('outcome, COUNT(*) as aggregate')
            ->where('workspace_id', $run->workspace_id)
            ->where('sync_run_id', $run->id)
            ->groupBy('outcome')
            ->pluck('aggregate', 'outcome');

        $synchronized = (int) ($counts[SyncLiveOutcome::Synchronized->value] ?? 0);
        $notApplied = (int) ($counts[SyncLiveOutcome::NotApplied->value] ?? 0);
        $partial = (int) ($counts[SyncLiveOutcome::Partial->value] ?? 0);
        $ambiguous = (int) ($counts[SyncLiveOutcome::Ambiguous->value] ?? 0);

        return new SyncLiveMerchantResultSummary(
            synchronizedCount: $synchronized,
            notAppliedCount: $notApplied,
            partialCount: $partial,
            ambiguousCount: $ambiguous,
            needsAttentionCount: $notApplied + $partial + $ambiguous,
            completedAtLabel: $run->completed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
        );
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
