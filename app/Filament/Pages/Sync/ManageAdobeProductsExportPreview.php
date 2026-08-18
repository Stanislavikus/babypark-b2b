<?php

namespace App\Filament\Pages\Sync;

use App\Enums\SyncPreviewMerchantPageState;
use App\Enums\SyncPreviewWorklistFilter;
use App\Enums\SyncSemanticOperation;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\AdobeProductsExportPreviewAuthorizationService;
use App\Services\Sync\SyncConfigurationLookupService;
use App\Services\Sync\SyncPreviewAdmissionService;
use App\Services\Sync\SyncPreviewMerchantReadService;
use App\Services\Sync\SyncPreviewWorklistPresenter;
use App\Services\Sync\SyncPreviewWorklistQuery;
use App\Support\Sync\Exceptions\SyncPreviewAdmissionException;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspaceSyncPreviewPermission;
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
    use RequiresFreshWorkspaceSyncPreviewPermission;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'sync-data-setup/{account}/products/export/preview';

    protected string $view = 'filament.pages.sync.manage-adobe-products-export-preview';

    #[Locked]
    public string $accountId;

    #[Locked]
    public ?string $displayedRunId = null;

    #[Locked]
    public ?string $configurationId = null;

    public string $platformName = '';

    public string $accountName = '';

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

    #[Url(as: 'filter')]
    public string $worklistFilter = 'needs_attention';

    #[Url(as: 'search')]
    public ?string $worklistSearch = '';

    public static function canAccess(array $parameters = []): bool
    {
        $user = Auth::user();
        $workspace = app(WorkspaceContext::class)->current();

        return $user instanceof User
            && app(AdobeProductsExportPreviewAuthorizationService::class)->canAccess($user, $workspace);
    }

    public function getTitle(): string|Htmlable
    {
        return __('sync_preview.page.title');
    }

    public function mount(string $account): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $this->resolveSyncPreviewWorkspace();
        $this->accountId = $account;

        if (! app(AdobeProductsExportPreviewAuthorizationService::class)->isEligiblePreviewTarget($user, $workspace, $account)) {
            abort(403);
        }

        $this->refreshPresentation();
    }

    public function updatedWorklistFilter(): void
    {
        $this->refreshWorklist();
    }

    public function updatedWorklistSearch(): void
    {
        $this->refreshWorklist();
    }

    public function startPreview(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $this->resolveSyncPreviewWorkspace();

        if (! app(AdobeProductsExportPreviewAuthorizationService::class)->canAccess($user, $workspace)) {
            abort(403);
        }

        $readModel = app(SyncPreviewMerchantReadService::class)->project($user, $workspace, $this->accountId);

        if ($readModel->hasActiveRun || ! $readModel->canStartPreview) {
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

    public function refreshPresentation(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $this->resolveSyncPreviewWorkspace();

        if (! app(AdobeProductsExportPreviewAuthorizationService::class)->canAccess($user, $workspace)) {
            abort(403);
        }

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

    private function refreshWorklist(
        ?User $user = null,
        ?Workspace $workspace = null,
    ): void {
        $user ??= Auth::user();
        $workspace ??= $this->resolveSyncPreviewWorkspace();
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
}
