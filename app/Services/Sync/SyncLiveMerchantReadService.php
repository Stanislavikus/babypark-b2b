<?php

namespace App\Services\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncLiveMerchantLifecycleState;
use App\Enums\SyncLiveMerchantSetupBarrier;
use App\Enums\SyncLiveOutcome;
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
use App\Support\Sync\Live\Merchant\SyncLiveAdmissionReadiness;
use App\Support\Sync\Live\Merchant\SyncLiveMerchantReadModel;
use App\Support\Sync\Live\Merchant\SyncLiveMerchantResultSummary;
use App\Support\Sync\Live\Merchant\SyncLivePreviewPrerequisiteSummary;
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
        private readonly SyncActiveRunReadQuery $activeRunReadQuery,
    ) {}

    public function project(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
    ): SyncLiveMerchantReadModel {
        if (! $this->authorizationService->isEligibleLiveTarget($actor, $workspace, $connectorAccountId)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $eligibilityProjection = $this->projectionQuery->resolveEligibility($workspace->id, $connectorAccountId);

        if ($eligibilityProjection === null) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $account = $this->accountReference(
            $eligibilityProjection->id,
            $eligibilityProjection->workspaceId,
            $eligibilityProjection->authProfile,
        );
        $configuration = $this->lookupService->findProductsDefaultContext($account);
        $canManageSetup = $this->authorizationService->canManageSetup($actor, $workspace);

        if (! $eligibilityProjection->isSetupUsable()) {
            return $this->model(
                setupBarrier: SyncLiveMerchantSetupBarrier::AccountUnavailable,
                configuration: $configuration,
                account: $account,
                canManageSetup: $canManageSetup,
            );
        }

        if ($configuration === null) {
            return $this->model(
                setupBarrier: SyncLiveMerchantSetupBarrier::ConfigurationAbsent,
                configuration: null,
                account: $account,
                canManageSetup: $canManageSetup,
            );
        }

        if ($configuration->operational_state === SyncConfigurationOperationalState::Paused) {
            return $this->model(
                setupBarrier: SyncLiveMerchantSetupBarrier::ConfigurationPaused,
                configuration: $configuration,
                account: $account,
                canManageSetup: $canManageSetup,
            );
        }

        if (! $configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export)) {
            return $this->model(
                setupBarrier: SyncLiveMerchantSetupBarrier::ExportUnavailable,
                configuration: $configuration,
                account: $account,
                canManageSetup: $canManageSetup,
            );
        }

        $configurationReady = $this->configurationReady($account, $configuration);
        $currentSetupRequired = ! $configurationReady;

        if ($currentSetupRequired) {
            return $this->model(
                setupBarrier: SyncLiveMerchantSetupBarrier::ConfigurationNotReady,
                configuration: $configuration,
                account: $account,
                canManageSetup: $canManageSetup,
                currentSetupRequired: true,
            );
        }

        $liveSupportAvailable = $this->syncSupportResolver->supports(
            $account,
            $configuration->data_domain,
            SyncSemanticOperation::Export,
            SyncRunMode::Live,
        );
        $previewPrerequisiteSatisfied = $this->hasPreviewEvidence($configuration);
        $blockedByActiveRun = $this->activeRunReadQuery->isBlocked($configuration->id);
        $activePreviewBlocking = $this->activeRunReadQuery->activePreviewBlocking($configuration->id);

        $activeLiveRun = $this->findActiveLiveRun($workspace->id, $configuration->id);

        if ($activeLiveRun !== null) {
            return $this->model(
                configuration: $configuration,
                account: $account,
                canManageSetup: $canManageSetup,
                lifecycleState: $activeLiveRun->status === SyncRunStatus::Running
                    ? SyncLiveMerchantLifecycleState::Running
                    : SyncLiveMerchantLifecycleState::Queued,
                displayedRun: $activeLiveRun,
                liveSupportAvailable: $liveSupportAvailable,
                previewPrerequisiteSatisfied: $previewPrerequisiteSatisfied,
                configurationReady: true,
                blockedByActiveRun: true,
                activePreviewBlocking: false,
                processedProductCount: $this->countProcessedProducts($activeLiveRun),
            );
        }

        $latestLiveRun = $this->findLatestTerminalLiveRun($workspace->id, $configuration->id);
        $lifecycleState = SyncLiveMerchantLifecycleState::None;
        $displayedRun = null;
        $resultSummary = null;

        if ($latestLiveRun !== null) {
            $lifecycleState = $latestLiveRun->status === SyncRunStatus::Completed
                ? SyncLiveMerchantLifecycleState::Completed
                : SyncLiveMerchantLifecycleState::Failed;
            $displayedRun = $latestLiveRun;
            $resultSummary = $latestLiveRun->status === SyncRunStatus::Completed
                ? $this->buildResultSummary($latestLiveRun)
                : null;
        }

        return $this->model(
            configuration: $configuration,
            account: $account,
            canManageSetup: $canManageSetup,
            lifecycleState: $lifecycleState,
            displayedRun: $displayedRun,
            resultSummary: $resultSummary,
            liveSupportAvailable: $liveSupportAvailable,
            previewPrerequisiteSatisfied: $previewPrerequisiteSatisfied,
            configurationReady: true,
            blockedByActiveRun: $blockedByActiveRun,
            activePreviewBlocking: $activePreviewBlocking,
        );
    }

    private function model(
        ?SyncLiveMerchantSetupBarrier $setupBarrier = null,
        ?SyncConfiguration $configuration = null,
        ?ConnectorAccount $account = null,
        bool $canManageSetup = false,
        SyncLiveMerchantLifecycleState $lifecycleState = SyncLiveMerchantLifecycleState::None,
        ?SyncRun $displayedRun = null,
        ?SyncLiveMerchantResultSummary $resultSummary = null,
        bool $liveSupportAvailable = false,
        bool $previewPrerequisiteSatisfied = false,
        bool $configurationReady = false,
        bool $blockedByActiveRun = false,
        bool $activePreviewBlocking = false,
        bool $currentSetupRequired = false,
        ?int $processedProductCount = null,
    ): SyncLiveMerchantReadModel {
        $configurationChanged = $displayedRun !== null
            && $configuration !== null
            && $displayedRun->configuration_revision !== $configuration->configuration_revision;

        $canStartLive = $setupBarrier === null
            && ! in_array($lifecycleState, [
                SyncLiveMerchantLifecycleState::Queued,
                SyncLiveMerchantLifecycleState::Running,
            ], true)
            && $liveSupportAvailable
            && $previewPrerequisiteSatisfied
            && $configurationReady
            && ! $blockedByActiveRun;

        $previewPrerequisiteSummary = null;

        if ($liveSupportAvailable && $previewPrerequisiteSatisfied && $configuration !== null) {
            $previewPrerequisiteSummary = $this->buildPreviewPrerequisiteSummary($configuration);
        }

        $admissionReadiness = new SyncLiveAdmissionReadiness(
            hasLivePermission: true,
            liveSupportEnabled: $liveSupportAvailable,
            configurationReady: $configurationReady,
            hasPreviewEvidence: $previewPrerequisiteSatisfied,
            activeRunBlocking: $blockedByActiveRun,
            canStartLive: $canStartLive,
        );

        return new SyncLiveMerchantReadModel(
            lifecycleState: $lifecycleState,
            setupBarrier: $setupBarrier,
            liveSupportAvailable: $liveSupportAvailable,
            previewPrerequisiteSatisfied: $previewPrerequisiteSatisfied,
            configurationReady: $configurationReady,
            blockedByActiveRun: $blockedByActiveRun,
            activePreviewBlocking: $activePreviewBlocking,
            canStartLive: $canStartLive,
            canManageSetup: $canManageSetup,
            configurationChangedSinceRun: $configurationChanged,
            displayedRunId: $displayedRun?->id,
            configurationId: $configuration?->id,
            resultSummary: $resultSummary,
            previewPrerequisiteSummary: $previewPrerequisiteSummary,
            currentSetupRequired: $currentSetupRequired,
            processedProductCount: $processedProductCount,
            admissionReadiness: $admissionReadiness,
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

    private function findLatestTerminalLiveRun(string $workspaceId, string $configurationId): ?SyncRun
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

    private function buildPreviewPrerequisiteSummary(SyncConfiguration $configuration): ?SyncLivePreviewPrerequisiteSummary
    {
        $previewRun = SyncRun::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('mode', SyncRunMode::Preview)
            ->where('semantic_operation', SyncSemanticOperation::Export)
            ->where('status', SyncRunStatus::Completed)
            ->where('configuration_revision', $configuration->configuration_revision)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($previewRun === null) {
            return null;
        }

        $counts = SyncRunItem::withoutWorkspaceScope()
            ->selectRaw('outcome, COUNT(*) as aggregate')
            ->where('workspace_id', $previewRun->workspace_id)
            ->where('sync_run_id', $previewRun->id)
            ->groupBy('outcome')
            ->pluck('aggregate', 'outcome');

        return new SyncLivePreviewPrerequisiteSummary(
            readyCount: (int) ($counts[SyncPreviewOutcome::Ready->value] ?? 0),
            warningCount: (int) ($counts[SyncPreviewOutcome::Warning->value] ?? 0),
            blockedCount: (int) ($counts[SyncPreviewOutcome::Blocked->value] ?? 0),
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
