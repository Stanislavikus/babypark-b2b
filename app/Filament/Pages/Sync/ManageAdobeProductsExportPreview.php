<?php

namespace App\Filament\Pages\Sync;

use App\Enums\SyncLiveMerchantLifecycleState;
use App\Enums\SyncLiveWorklistFilter;
use App\Enums\SyncPreviewMerchantPageState;
use App\Enums\SyncPreviewWorklistFilter;
use App\Enums\SyncSemanticOperation;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\AdobeProductsExportLiveAuthorizationService;
use App\Services\Sync\AdobeProductsExportPreviewAuthorizationService;
use App\Services\Sync\ConnectorAccountLayerBSetupProjectionQuery;
use App\Services\Sync\SyncActiveRunReadQuery;
use App\Services\Sync\SyncConfigurationLookupService;
use App\Services\Sync\SyncLiveAdmissionService;
use App\Services\Sync\SyncLiveMerchantReadService;
use App\Services\Sync\SyncLiveWorklistPresenter;
use App\Services\Sync\SyncLiveWorklistQuery;
use App\Services\Sync\SyncPreviewAdmissionService;
use App\Services\Sync\SyncPreviewMerchantReadService;
use App\Services\Sync\SyncPreviewWorklistPresenter;
use App\Services\Sync\SyncPreviewWorklistQuery;
use App\Support\Sync\Exceptions\SyncLiveAdmissionException;
use App\Support\Sync\Exceptions\SyncPreviewAdmissionException;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspaceAdobeProductsExportExecutionAccess;
use App\Support\Workspace\WorkspaceContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Throwable;

class ManageAdobeProductsExportPreview extends Page
{
    use RequiresFreshWorkspaceAdobeProductsExportExecutionAccess;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'sync-data-setup/{account}/products/export/preview';

    protected string $view = 'filament.pages.sync.manage-adobe-products-export-preview';

    #[Locked]
    public string $accountId;

    #[Locked]
    public ?string $displayedRunId = null;

    #[Locked]
    public ?string $liveDisplayedRunId = null;

    #[Locked]
    public ?string $configurationId = null;

    public string $platformName = '';

    public string $accountName = '';

    public bool $previewSectionVisible = false;

    public bool $liveSectionVisible = false;

    public string $pageState = '';

    public bool $canManageSetup = false;

    public bool $canStartPreview = false;

    public bool $configurationChangedSinceRun = false;

    public bool $currentSetupRequired = false;

    public bool $pollActive = false;

    public ?string $lifecycleLabel = null;

    public ?string $resultAttentionStatement = null;

    public ?int $readyCount = null;

    public ?int $warningCount = null;

    public ?int $blockedCount = null;

    public ?string $completedAtLabel = null;

    /** @var list<array<string, mixed>> */
    public array $worklistRows = [];

    public string $liveLifecycleState = 'none';

    public ?string $liveSetupBarrier = null;

    public bool $liveSupportAvailable = false;

    public bool $livePreviewPrerequisiteSatisfied = false;

    public bool $liveBlockedByActiveRun = false;

    public bool $liveActivePreviewBlocking = false;

    public bool $canStartLive = false;

    public bool $liveResultPresentationTrusted = true;

    public bool $liveConfigurationChangedSinceRun = false;

    public bool $liveCurrentSetupRequired = false;

    public bool $livePollActive = false;

    public ?string $liveLifecycleLabel = null;

    public ?string $liveResultAttentionStatement = null;

    public ?int $liveSynchronizedCount = null;

    public ?int $liveNotAppliedCount = null;

    public ?int $livePartialCount = null;

    public ?int $liveAmbiguousCount = null;

    public ?string $liveCompletedAtLabel = null;

    public ?int $liveProcessedProductCount = null;

    public ?int $livePreviewReadyCount = null;

    public ?int $livePreviewWarningCount = null;

    public ?int $livePreviewBlockedCount = null;

    /** @var list<array<string, mixed>> */
    public array $liveWorklistRows = [];

    #[Url(as: 'filter')]
    public string $worklistFilter = 'needs_attention';

    #[Url(as: 'search')]
    public ?string $worklistSearch = '';

    #[Url(as: 'live_filter')]
    public string $liveWorklistFilter = 'needs_attention';

    #[Url(as: 'live_search')]
    public ?string $liveWorklistSearch = '';

    public static function canAccess(array $parameters = []): bool
    {
        $user = Auth::user();
        $workspace = app(WorkspaceContext::class)->current();

        if (! $user instanceof User) {
            return false;
        }

        return app(AdobeProductsExportPreviewAuthorizationService::class)->canAccess($user, $workspace)
            || app(AdobeProductsExportLiveAuthorizationService::class)->canAccessLive($user, $workspace);
    }

    public function getTitle(): string|Htmlable
    {
        return __('sync_preview.page.title');
    }

    public function mount(string $account): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $this->resolveAdobeProductsExportExecutionWorkspace();
        $this->accountId = $account;

        $previewEligible = app(AdobeProductsExportPreviewAuthorizationService::class)
            ->isEligiblePreviewTarget($user, $workspace, $account);
        $liveEligible = app(AdobeProductsExportLiveAuthorizationService::class)
            ->isEligibleLiveTarget($user, $workspace, $account);

        if (! $previewEligible && ! $liveEligible) {
            abort(403);
        }

        $this->refreshPresentation();
    }

    public function updatedWorklistFilter(): void
    {
        if (! $this->previewSectionVisible) {
            return;
        }

        $this->refreshWorklist();
    }

    public function updatedWorklistSearch(): void
    {
        if (! $this->previewSectionVisible) {
            return;
        }

        $this->refreshWorklist();
    }

    public function updatedLiveWorklistFilter(): void
    {
        if (! $this->liveSectionVisible) {
            return;
        }

        $this->refreshLiveWorklist();
    }

    public function updatedLiveWorklistSearch(): void
    {
        if (! $this->liveSectionVisible) {
            return;
        }

        $this->refreshLiveWorklist();
    }

    public function startPreview(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $this->resolveAdobeProductsExportExecutionWorkspace();

        if (! app(AdobeProductsExportPreviewAuthorizationService::class)->canAccess($user, $workspace)) {
            abort(403);
        }

        $readModel = app(SyncPreviewMerchantReadService::class)->project($user, $workspace, $this->accountId);

        if ($readModel->hasActiveRun || ! $readModel->canStartPreview || $this->isBlockedByAnyActiveRun($workspace, $readModel->configurationId)) {
            $this->refreshPresentation();

            return;
        }

        try {
            $account = app(AdobeProductsExportPreviewAuthorizationService::class)
                ->resolveConnectorAccount($user, $workspace, $this->accountId);
            $configuration = app(SyncConfigurationLookupService::class)->findProductsDefaultContext($account);

            if ($configuration === null) {
                $this->refreshPresentation();

                return;
            }

            app(SyncPreviewAdmissionService::class)->admit(
                $user,
                $account,
                $configuration->id,
                SyncSemanticOperation::Export,
            );
        } catch (AuthorizationException) {
            abort(403);
        } catch (SyncPreviewAdmissionException) {
            $this->refreshPresentation();

            return;
        } catch (Throwable $exception) {
            Log::warning('sync_preview.start_failed', [
                'workspace_id' => $workspace->id,
                'connector_account_id' => $this->accountId,
                'exception' => $exception,
            ]);

            Notification::make()
                ->title(__('sync_preview.errors.start_failed'))
                ->danger()
                ->send();
        }

        $this->refreshPresentation();
    }

    public function startLive(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $this->resolveAdobeProductsExportExecutionWorkspace();

        if (! app(AdobeProductsExportLiveAuthorizationService::class)->canAccessLive($user, $workspace)) {
            abort(403);
        }

        $readModel = app(SyncLiveMerchantReadService::class)->project($user, $workspace, $this->accountId);

        if (! $readModel->canStartLive) {
            $this->refreshPresentation();

            return;
        }

        try {
            $account = app(AdobeProductsExportLiveAuthorizationService::class)
                ->resolveConnectorAccount($user, $workspace, $this->accountId);
            $configuration = app(SyncConfigurationLookupService::class)->findProductsDefaultContext($account);

            if ($configuration === null) {
                $this->refreshPresentation();

                return;
            }

            app(SyncLiveAdmissionService::class)->admit(
                $user,
                $account,
                $configuration->id,
            );
        } catch (AuthorizationException) {
            abort(403);
        } catch (SyncLiveAdmissionException) {
            $this->refreshPresentation();

            return;
        } catch (Throwable $exception) {
            Log::warning('sync_live.start_failed', [
                'workspace_id' => $workspace->id,
                'connector_account_id' => $this->accountId,
                'exception' => $exception,
            ]);

            Notification::make()
                ->title(__('sync_live.errors.start_failed'))
                ->danger()
                ->send();
        }

        $this->refreshPresentation();
    }

    public function refreshPresentation(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $this->resolveAdobeProductsExportExecutionWorkspace();

        $previewAuth = app(AdobeProductsExportPreviewAuthorizationService::class);
        $liveAuth = app(AdobeProductsExportLiveAuthorizationService::class);

        if (! $previewAuth->canAccess($user, $workspace) && ! $liveAuth->canAccessLive($user, $workspace)) {
            abort(403);
        }

        $this->previewSectionVisible = $previewAuth->isEligiblePreviewTarget($user, $workspace, $this->accountId);
        $this->liveSectionVisible = $liveAuth->isEligibleLiveTarget($user, $workspace, $this->accountId);

        if ($this->previewSectionVisible) {
            $this->refreshPreviewPresentation($user, $workspace);
        } else {
            $this->resetPreviewPresentation();
        }

        if ($this->liveSectionVisible) {
            $this->refreshLivePresentation($user, $workspace);
        } else {
            $this->resetLivePresentation();
        }

        $this->applyCommonActiveRunGate($workspace);

        if (! $this->previewSectionVisible && $this->liveSectionVisible) {
            $projection = app(ConnectorAccountLayerBSetupProjectionQuery::class)
                ->resolve($workspace->id, $this->accountId);

            if ($projection === null) {
                abort(403);
            }

            $this->platformName = $projection->platformName;
            $this->accountName = $projection->accountName;
        }

        $this->pollActive = $this->pollActive
            || $this->livePollActive
            || $this->isBlockedByAnyActiveRun($workspace, $this->configurationId);
    }

    private function applyCommonActiveRunGate(Workspace $workspace): void
    {
        if ($this->configurationId === null) {
            return;
        }

        if (! $this->isBlockedByAnyActiveRun($workspace, $this->configurationId)) {
            return;
        }

        $this->canStartPreview = false;
        $this->canStartLive = false;
    }

    private function isBlockedByAnyActiveRun(Workspace $workspace, ?string $configurationId): bool
    {
        if ($configurationId === null) {
            return false;
        }

        return app(SyncActiveRunReadQuery::class)->isBlocked($workspace->id, $configurationId);
    }

    private function refreshPreviewPresentation(User $user, Workspace $workspace): void
    {
        $readModel = app(SyncPreviewMerchantReadService::class)->project($user, $workspace, $this->accountId);

        $this->platformName = $readModel->platformName;
        $this->accountName = $readModel->accountName;
        $this->pageState = $readModel->pageState->value;
        $this->canManageSetup = $readModel->canManageSetup;
        $this->canStartPreview = $readModel->canStartPreview;
        $this->configurationChangedSinceRun = $readModel->configurationChangedSinceRun;
        $this->currentSetupRequired = $readModel->currentSetupRequired;
        $this->displayedRunId = $readModel->displayedRunId;
        $this->configurationId = $readModel->configurationId;
        $this->pollActive = in_array($readModel->pageState, [
            SyncPreviewMerchantPageState::Queued,
            SyncPreviewMerchantPageState::Running,
        ], true);

        $this->lifecycleLabel = match ($readModel->pageState) {
            SyncPreviewMerchantPageState::Queued => __('sync_preview.lifecycle.queued'),
            SyncPreviewMerchantPageState::Running => __('sync_preview.lifecycle.running'),
            default => null,
        };

        $this->readyCount = null;
        $this->warningCount = null;
        $this->blockedCount = null;
        $this->completedAtLabel = null;
        $this->resultAttentionStatement = null;
        $this->worklistRows = [];

        if ($readModel->pageState === SyncPreviewMerchantPageState::Completed && $readModel->resultSummary !== null) {
            $summary = $readModel->resultSummary;
            $this->readyCount = $summary->readyCount;
            $this->warningCount = $summary->warningCount;
            $this->blockedCount = $summary->blockedCount;
            $this->completedAtLabel = $summary->completedAtLabel;

            if ($summary->needsAttentionCount > 0) {
                $this->resultAttentionStatement = __('sync_preview.results.needs_attention', [
                    'count' => $summary->needsAttentionCount,
                ]);
            } else {
                $this->resultAttentionStatement = __('sync_preview.results.all_ready');
            }

            $this->refreshWorklist($user, $workspace);
        }
    }

    private function refreshLivePresentation(User $user, Workspace $workspace): void
    {
        $readModel = app(SyncLiveMerchantReadService::class)->project($user, $workspace, $this->accountId);

        $this->liveLifecycleState = $readModel->lifecycleState->value;
        $this->liveSetupBarrier = $readModel->setupBarrier?->value;
        $this->canManageSetup = $readModel->canManageSetup || $this->canManageSetup;
        $this->canStartLive = $readModel->canStartLive;
        $this->liveSupportAvailable = $readModel->liveSupportAvailable;
        $this->livePreviewPrerequisiteSatisfied = $readModel->previewPrerequisiteSatisfied;
        $this->liveBlockedByActiveRun = $readModel->blockedByActiveRun;
        $this->liveActivePreviewBlocking = $readModel->activePreviewBlocking;
        $this->liveResultPresentationTrusted = $readModel->resultPresentationTrusted;
        $this->liveConfigurationChangedSinceRun = $readModel->configurationChangedSinceRun;
        $this->liveCurrentSetupRequired = $readModel->currentSetupRequired;
        $this->liveDisplayedRunId = $readModel->displayedRunId;
        $this->configurationId = $readModel->configurationId ?? $this->configurationId;
        $this->livePollActive = in_array($readModel->lifecycleState, [
            SyncLiveMerchantLifecycleState::Queued,
            SyncLiveMerchantLifecycleState::Running,
        ], true);

        $this->liveLifecycleLabel = match ($readModel->lifecycleState) {
            SyncLiveMerchantLifecycleState::Queued => __('sync_live.lifecycle.queued'),
            SyncLiveMerchantLifecycleState::Running => __('sync_live.lifecycle.running'),
            default => null,
        };

        $this->liveSynchronizedCount = null;
        $this->liveNotAppliedCount = null;
        $this->livePartialCount = null;
        $this->liveAmbiguousCount = null;
        $this->liveCompletedAtLabel = null;
        $this->liveResultAttentionStatement = null;
        $this->liveProcessedProductCount = $readModel->processedProductCount;
        $this->livePreviewReadyCount = null;
        $this->livePreviewWarningCount = null;
        $this->livePreviewBlockedCount = null;
        $this->liveWorklistRows = [];

        if ($readModel->previewPrerequisiteSummary !== null) {
            $this->livePreviewReadyCount = $readModel->previewPrerequisiteSummary->readyCount;
            $this->livePreviewWarningCount = $readModel->previewPrerequisiteSummary->warningCount;
            $this->livePreviewBlockedCount = $readModel->previewPrerequisiteSummary->blockedCount;
        }

        if ($readModel->lifecycleState === SyncLiveMerchantLifecycleState::Completed
            && $readModel->resultPresentationTrusted
            && $readModel->resultSummary !== null) {
            $summary = $readModel->resultSummary;
            $this->liveSynchronizedCount = $summary->synchronizedCount;
            $this->liveNotAppliedCount = $summary->notAppliedCount;
            $this->livePartialCount = $summary->partialCount;
            $this->liveAmbiguousCount = $summary->ambiguousCount;
            $this->liveCompletedAtLabel = $summary->completedAtLabel;

            if ($summary->ambiguousCount > 0) {
                $this->liveResultAttentionStatement = __('sync_live.results.ambiguous_attention', [
                    'count' => $summary->ambiguousCount,
                ]);
            } elseif ($summary->needsAttentionCount > 0) {
                $this->liveResultAttentionStatement = __('sync_live.results.needs_attention', [
                    'count' => $summary->needsAttentionCount,
                ]);
            } else {
                $this->liveResultAttentionStatement = __('sync_live.results.all_synchronized');
            }

            $this->refreshLiveWorklist();
        } elseif ($readModel->lifecycleState === SyncLiveMerchantLifecycleState::Completed
            && ! $readModel->resultPresentationTrusted) {
            $this->liveResultAttentionStatement = __('sync_live.results.untrusted');
        }
    }

    private function resetPreviewPresentation(): void
    {
        $this->pageState = '';
        $this->canStartPreview = false;
        $this->configurationChangedSinceRun = false;
        $this->currentSetupRequired = false;
        $this->pollActive = false;
        $this->lifecycleLabel = null;
        $this->resultAttentionStatement = null;
        $this->readyCount = null;
        $this->warningCount = null;
        $this->blockedCount = null;
        $this->completedAtLabel = null;
        $this->worklistRows = [];
        $this->displayedRunId = null;
    }

    private function resetLivePresentation(): void
    {
        $this->liveLifecycleState = 'none';
        $this->liveSetupBarrier = null;
        $this->canStartLive = false;
        $this->liveResultPresentationTrusted = true;
        $this->liveSupportAvailable = false;
        $this->livePreviewPrerequisiteSatisfied = false;
        $this->liveBlockedByActiveRun = false;
        $this->liveActivePreviewBlocking = false;
        $this->liveConfigurationChangedSinceRun = false;
        $this->liveCurrentSetupRequired = false;
        $this->livePollActive = false;
        $this->liveLifecycleLabel = null;
        $this->liveResultAttentionStatement = null;
        $this->liveSynchronizedCount = null;
        $this->liveNotAppliedCount = null;
        $this->livePartialCount = null;
        $this->liveAmbiguousCount = null;
        $this->liveCompletedAtLabel = null;
        $this->liveProcessedProductCount = null;
        $this->livePreviewReadyCount = null;
        $this->livePreviewWarningCount = null;
        $this->livePreviewBlockedCount = null;
        $this->liveWorklistRows = [];
        $this->liveDisplayedRunId = null;
    }

    private function refreshWorklist(
        ?User $user = null,
        ?Workspace $workspace = null,
    ): void {
        $user ??= Auth::user();
        $workspace ??= $this->resolveAdobeProductsExportExecutionWorkspace();
        $runId = $this->displayedRunId;
        $configurationId = $this->configurationId;

        if (! $user instanceof User || $runId === null) {
            $this->worklistRows = [];

            return;
        }

        $run = SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('id', $runId)
            ->first();

        if ($run === null || ! app(SyncPreviewWorklistQuery::class)->isWorklistRenderable($run)) {
            $this->worklistRows = [];

            return;
        }

        $configuration = null;

        if ($configurationId !== null) {
            $configuration = SyncConfiguration::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('id', $configurationId)
                ->first();
        }

        $filter = SyncPreviewWorklistFilter::tryFrom($this->worklistFilter)
            ?? SyncPreviewWorklistFilter::NeedsAttention;

        $query = app(SyncPreviewWorklistQuery::class)->baseQuery($workspace, $run);
        $query = app(SyncPreviewWorklistQuery::class)->applyOutcomeFilter($query, $filter);
        $query = app(SyncPreviewWorklistQuery::class)->applySearch($query, (string) ($this->worklistSearch ?? ''));

        $items = $query
            ->with(['product.variants' => fn ($variantQuery) => $variantQuery->where('is_active', true)])
            ->orderBy('product_id')
            ->get();

        $this->worklistRows = app(SyncPreviewWorklistPresenter::class)->presentRows(
            $run,
            $configuration,
            $this->accountId,
            $user,
            $workspace,
            $items,
        );
    }

    private function refreshLiveWorklist(): void
    {
        $workspace = $this->resolveAdobeProductsExportExecutionWorkspace();
        $runId = $this->liveDisplayedRunId;

        if ($runId === null) {
            $this->liveWorklistRows = [];

            return;
        }

        $run = SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('id', $runId)
            ->first();

        if ($run === null || ! app(SyncLiveWorklistQuery::class)->isWorklistRenderable($run)) {
            $this->liveWorklistRows = [];

            return;
        }

        $filter = SyncLiveWorklistFilter::tryFrom($this->liveWorklistFilter)
            ?? SyncLiveWorklistFilter::NeedsAttention;

        $query = app(SyncLiveWorklistQuery::class)->baseQuery($workspace, $run);
        $query = app(SyncLiveWorklistQuery::class)->applyOutcomeFilter($query, $filter);
        $query = app(SyncLiveWorklistQuery::class)->applySearch($query, (string) ($this->liveWorklistSearch ?? ''));

        $items = $query
            ->with(['product.variants' => fn ($variantQuery) => $variantQuery->where('is_active', true)])
            ->orderBy('product_id')
            ->get();

        $this->liveWorklistRows = app(SyncLiveWorklistPresenter::class)->presentRows($items);
    }
}
