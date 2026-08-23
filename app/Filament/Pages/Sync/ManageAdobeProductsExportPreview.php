<?php

namespace App\Filament\Pages\Sync;

use App\Enums\EntityTrust\EntityTrustFailureReason;
use App\Enums\EntityTrust\EntityTrustReadinessStatus;
use App\Enums\SyncLiveMerchantLifecycleState;
use App\Enums\SyncLiveWorklistFilter;
use App\Enums\SyncPreviewMerchantPageState;
use App\Enums\SyncPreviewWorklistFilter;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\AdobeProductsExportLiveAuthorizationService;
use App\Services\Sync\AdobeProductsExportPreviewAuthorizationService;
use App\Services\Sync\ConnectorAccountLayerBSetupProjectionQuery;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustAuthorizationService;
use App\Services\Sync\EntityTrust\EntityTrustFailureReasonPresenter;
use App\Services\Sync\EntityTrust\EntityTrustMerchantOrchestrator;
use App\Services\Sync\EntityTrust\EntityTrustMerchantReadService;
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
use App\Support\Sync\EntityTrust\EntityTrustMerchantOutcome;
use App\Support\Sync\Exceptions\SyncLiveAdmissionException;
use App\Support\Sync\Exceptions\SyncPreviewAdmissionException;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspaceAdobeProductsExportExecutionAccess;
use App\Support\Workspace\WorkspaceContext;
use Closure;
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

    // ----- Stage 3E-R2b-2: Entity Trust merchant UI state -----

    #[Locked]
    public ?string $entityTrustProductId = null;

    #[Locked]
    public ?string $entityTrustReviewFlowId = null;

    /**
     * Merchant-typed parent Magento SKU hint used by a configurable Relink
     * Review. Bound to the relink input via wire:model.live. Cleared by
     * reviewEntityTrustReview and resetEntityTrustActiveReviewState. Not
     * locked: the merchant must be able to type into the input.
     */
    public ?string $entityTrustRelinkParentSku = null;

    /**
     * Merchant-typed parent Magento SKU hint used by an InitialLinkRequired
     * Review on a configurable family. Server-side authoritative intent
     * decides whether this hint is actually applied. Bound to the initial
     * parent input via wire:model.live. Not locked: the merchant must be
     * able to type into the input.
     */
    public ?string $entityTrustInitialLinkParentSku = null;

    /**
     * Authoritative R2b-1 family flag for the active product, lifted from
     * the working set row. Required by the orchestrator to choose between
     * SimpleVariant and ConfigurableExistingParent paths without relying on
     * a guessed heuristic. Cleared on every reset.
     */
    public bool $entityTrustReviewIsConfigurable = false;

    /** @var list<array<string, mixed>> */
    public array $entityTrustWorkingSet = [];

    public bool $entityTrustSectionVisible = false;

    public bool $entityTrustCanReviewOrConfirm = false;

    public ?string $entityTrustOutcomeCategory = null;

    public ?string $entityTrustOutcomeLabel = null;

    public ?string $entityTrustOutcomeExplanation = null;

    public ?string $entityTrustOutcomeAction = null;

    public ?string $entityTrustOutcomeProductName = null;

    public ?string $entityTrustOutcomePrimarySku = null;

    public bool $entityTrustOutcomeIsConfigurable = false;

    public ?string $entityTrustOutcomeAvailableAction = null;

    public bool $entityTrustOutcomeIsConfirmation = false;

    public bool $entityTrustOutcomeIsStale = false;

    public bool $entityTrustOutcomeIsSuccess = false;

    public bool $entityTrustOutcomeIsConflict = false;

    public bool $entityTrustOutcomeIsSecurity = false;

    public bool $entityTrustOutcomeIsRemoteFailure = false;

    public bool $entityTrustOutcomeIsRelinkRequired = false;

    public bool $entityTrustOutcomeReadyForConfirmation = false;

    public ?string $entityTrustActiveReviewFlowId = null;

    public ?string $entityTrustActiveReviewProductId = null;

    /** @var list<array<string, mixed>> */
    public array $entityTrustActiveSubjects = [];

    /** @var list<string> */
    public array $entityTrustActiveExtraChildSkus = [];

    public bool $entityTrustActiveExtraChildrenAvailable = false;

    public ?string $entityTrustActiveMode = null;

    public ?string $entityTrustErrorTitle = null;

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
        $this->refreshPresentation();
    }

    public function updatedWorklistSearch(): void
    {
        $this->refreshPresentation();
    }

    public function updatedLiveWorklistFilter(): void
    {
        $this->refreshPresentation();
    }

    public function updatedLiveWorklistSearch(): void
    {
        $this->refreshPresentation();
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

        $this->canManageSetup = false;

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

        // Stage 3E-R2b-2: entity trust section only surfaces inside the Live area.
        if ($this->liveSectionVisible) {
            $this->refreshEntityTrustPresentation($user, $workspace);
        } else {
            $this->resetEntityTrustPresentation();
        }

        $this->pollActive = $this->pollActive
            || $this->livePollActive
            || $this->isBlockedByAnyActiveRun($workspace, $this->configurationId);
    }

    // ----- Stage 3E-R2b-2: Entity Trust merchant surface methods -----

    public function requestEntityTrustReview(string $productId): void
    {
        $row = $this->findEntityTrustWorkingSetRow($productId);

        // If the actor has lost the working set (e.g. a stale review from
        // before a refresh) we still call into the orchestrator with
        // authoritative defaults: simple family, no hint. The orchestrator
        // surfaces an Unauthorized / stale-review failure if the actor is no
        // longer entitled, which is the correct merchant-safe behavior.
        $isConfigurable = (bool) ($row['is_configurable_family'] ?? false);
        $readiness = (string) ($row['readiness_value'] ?? '');

        // For an InitialLinkRequired review on a configurable family the
        // merchant may have supplied an existing Magento parent SKU. R2b-1
        // requires that hint; without it the resolver fails closed. We
        // forward whatever the merchant typed (or null) — never a made-up
        // value. If a trusted parent already supplies the authoritative SKU
        // the hint is ignored by the intent resolver, so this never asks the
        // merchant to provide a meaningless replacement.
        $hint = null;
        if ($isConfigurable && $readiness === EntityTrustReadinessStatus::InitialLinkRequired->value) {
            $hint = $this->entityTrustInitialLinkParentSku;
        }

        $this->entityTrustReviewIsConfigurable = $isConfigurable;
        $this->entityTrustRelinkParentSku = null;

        $this->dispatchEntityTrustOrchestrator(
            function (EntityTrustMerchantOrchestrator $orchestrator, User $user, Workspace $workspace, ConnectorAccount $account, Product $product) use ($isConfigurable, $hint): EntityTrustMerchantOutcome {
                return $orchestrator->requestReview(
                    $user,
                    $workspace,
                    $account,
                    $product,
                    isConfigurableFamily: $isConfigurable,
                    explicitRelink: false,
                    existingParentSkuHint: $hint,
                );
            },
            $productId,
        );
    }

    public function requestEntityTrustRelink(string $productId): void
    {
        $row = $this->findEntityTrustWorkingSetRow($productId);
        $isConfigurable = (bool) ($row['is_configurable_family'] ?? false);

        $merchantSuppliedParentSku = $isConfigurable
            ? $this->entityTrustRelinkParentSku
            : null;

        $this->entityTrustReviewIsConfigurable = $isConfigurable;

        $this->dispatchEntityTrustOrchestrator(
            function (EntityTrustMerchantOrchestrator $orchestrator, User $user, Workspace $workspace, ConnectorAccount $account, Product $product) use ($isConfigurable, $merchantSuppliedParentSku): EntityTrustMerchantOutcome {
                return $orchestrator->requestRelink(
                    $user,
                    $workspace,
                    $account,
                    $product,
                    isConfigurableFamily: $isConfigurable,
                    newMagentoParentSku: $merchantSuppliedParentSku,
                );
            },
            $productId,
        );
    }

    public function confirmEntityTrust(): void
    {
        $productId = $this->entityTrustProductId;
        $flowId = $this->entityTrustReviewFlowId;

        if ($productId === null || $flowId === null) {
            $this->resetEntityTrustActiveReviewState();
            $this->refreshPresentation();

            return;
        }

        // Lift the family flag from the active review state so the
        // orchestrator never has to re-derive it from a heuristic.
        $isConfigurable = $this->entityTrustReviewIsConfigurable;

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $this->resolveAdobeProductsExportExecutionWorkspace();

        try {
            $account = $this->resolveEntityTrustAccount($user, $workspace);
        } catch (AuthorizationException) {
            abort(403);
        }

        if ($account === null) {
            abort(403);
        }

        $product = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('id', $productId)
            ->with('variants')
            ->first();

        if ($product === null) {
            $this->resetEntityTrustActiveReviewState();
            $this->refreshPresentation();

            return;
        }

        $outcome = app(EntityTrustMerchantOrchestrator::class)->confirm(
            $user,
            $workspace,
            $account,
            $product,
            isConfigurableFamily: $isConfigurable,
            reviewFlowId: $flowId,
        );

        $this->applyEntityTrustOutcome($outcome, previousFlowId: $flowId);
    }

    public function cancelEntityTrustFlow(): void
    {
        $flowId = $this->entityTrustReviewFlowId;

        if ($flowId !== null) {
            app(EntityTrustMerchantOrchestrator::class)->cancelFlow($flowId);
        }

        $this->resetEntityTrustActiveReviewState();
        $this->refreshEntityTrustPresentation(
            Auth::user() instanceof User ? Auth::user() : null,
            app(WorkspaceContext::class)->current(),
        );
    }

    private function dispatchEntityTrustOrchestrator(
        Closure $callback,
        string $productId,
    ): void {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $this->resolveAdobeProductsExportExecutionWorkspace();

        // Resolve the account if the actor has at least live visibility. If the
        // actor lacks the dual permission, fall through with a null account so
        // the orchestrator can produce a safe Unauthorized outcome instead of
        // aborting the Livewire request.
        $account = null;
        try {
            $account = $this->resolveEntityTrustAccount($user, $workspace);
        } catch (AuthorizationException) {
            // fall through
        }

        $product = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('id', $productId)
            ->with('variants')
            ->first();

        if ($product === null) {
            $this->entityTrustErrorTitle = __('entity_trust.errors.product_not_found');
            $this->refreshEntityTrustPresentation($user, $workspace);

            return;
        }

        if ($account === null) {
            // No eligible account (or no dual permission): produce a safe
            // Unauthorized outcome through the orchestrator so the merchant
            // gets a copy-keyed Security category, not an HTTP abort.
            $this->entityTrustProductId = $product->id;
            $this->applyEntityTrustOutcome(
                $this->makeUnauthorizedOutcomeForNoAccount((string) $product->id),
                previousFlowId: null,
            );

            return;
        }

        $this->entityTrustProductId = $product->id;

        $outcome = $callback(
            app(EntityTrustMerchantOrchestrator::class),
            $user,
            $workspace,
            $account,
            $product,
        );

        $this->applyEntityTrustOutcome($outcome, previousFlowId: null);
    }

    /**
     * Find a working set row for the given product. Returns an empty array
     * (not null) when the row is not present, so the caller can read fields
     * with `??` defaults. The working set is the only authoritative source
     * of `is_configurable_family` and the readiness value at the moment a
     * review/relink is requested.
     *
     * @return array<string, mixed>
     */
    private function findEntityTrustWorkingSetRow(string $productId): array
    {
        foreach ($this->entityTrustWorkingSet as $row) {
            if (($row['product_id'] ?? null) === $productId) {
                return $row;
            }
        }

        return [];
    }

    private function makeUnauthorizedOutcomeForNoAccount(string $productId): EntityTrustMerchantOutcome
    {
        $presenter = app(EntityTrustFailureReasonPresenter::class);

        $presentation = $presenter->present(EntityTrustFailureReason::Unauthorized);

        return new EntityTrustMerchantOutcome(
            product_id: $productId,
            productName: '',
            primary_sku: null,
            is_configurable_family: false,
            reason: EntityTrustFailureReason::Unauthorized,
            category: $presentation['category'],
            label_key: $presentation['label_key'],
            explanation_key: $presentation['explanation_key'],
            available_action: $presentation['available_action'],
            review_flow_id: null,
            subjects: [],
            extra_remote_child_skus: [],
            extra_remote_children_available: false,
        );
    }

    private function applyEntityTrustOutcome(EntityTrustMerchantOutcome $outcome, ?string $previousFlowId): void
    {
        // Always discard any previous flow that is no longer the current one
        // (e.g. a new review replaces a stale one before confirm).
        if ($previousFlowId !== null && $previousFlowId !== $outcome->review_flow_id) {
            app(EntityTrustMerchantOrchestrator::class)->cancelFlow($previousFlowId);
        }

        $this->entityTrustOutcomeCategory = $outcome->category->value;
        $this->entityTrustOutcomeLabel = __($outcome->label_key);
        $this->entityTrustOutcomeExplanation = __($outcome->explanation_key);
        $this->entityTrustOutcomeAction = $outcome->available_action;
        $this->entityTrustOutcomeProductName = $outcome->productName;
        $this->entityTrustOutcomePrimarySku = $outcome->primary_sku;
        $this->entityTrustOutcomeIsConfigurable = $outcome->is_configurable_family;
        $this->entityTrustOutcomeAvailableAction = $outcome->available_action;
        $this->entityTrustOutcomeIsConfirmation = $this->isConfirmationReason($outcome->reason);
        $this->entityTrustOutcomeIsStale = $outcome->category->value === 'stale_review';
        $this->entityTrustOutcomeIsSuccess = $outcome->category->value === 'success';
        $this->entityTrustOutcomeIsConflict = $outcome->category->value === 'identity_conflict';
        $this->entityTrustOutcomeIsSecurity = $outcome->category->value === 'security';
        $this->entityTrustOutcomeIsRemoteFailure = $outcome->category->value === 'remote_verification_failure';
        $this->entityTrustOutcomeIsRelinkRequired = $outcome->category->value === 'relink_required'
            || $outcome->available_action === 'relink';
        $this->entityTrustOutcomeReadyForConfirmation = $outcome->review_flow_id !== null;

        $this->entityTrustActiveReviewFlowId = $outcome->review_flow_id;
        $this->entityTrustActiveReviewProductId = $outcome->review_flow_id !== null ? $outcome->product_id : null;
        $this->entityTrustReviewFlowId = $outcome->review_flow_id;
        $this->entityTrustActiveMode = $outcome->review_flow_id !== null
            ? $this->mapModeLabel($outcome)
            : null;
        $this->entityTrustActiveSubjects = $this->presentActiveSubjects($outcome);
        $this->entityTrustActiveExtraChildSkus = $outcome->extra_remote_child_skus;
        $this->entityTrustActiveExtraChildrenAvailable = $outcome->extra_remote_children_available;

        $this->entityTrustErrorTitle = null;
    }

    private function isConfirmationReason(EntityTrustFailureReason $reason): bool
    {
        return $reason === EntityTrustFailureReason::ConfirmationCompleted
            || $reason === EntityTrustFailureReason::RelinkCompleted
            || $reason === EntityTrustFailureReason::AlreadyConfirmed;
    }

    private function mapModeLabel(EntityTrustMerchantOutcome $outcome): string
    {
        return $outcome->is_configurable_family
            ? __('entity_trust.mode.configurable_existing_parent')
            : __('entity_trust.mode.simple_variant');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presentActiveSubjects(EntityTrustMerchantOutcome $outcome): array
    {
        $rows = [];

        foreach ($outcome->subjects as $subject) {
            $rows[] = [
                'role' => $subject->role,
                'expected_sku' => $subject->expected_sku,
                'magento_type_label' => __($subject->magento_type_label),
                'platform_name' => $subject->platform_name,
                'declared_image_count' => $subject->declared_image_count,
                'declared_roles_summary' => $subject->declared_roles_summary,
                'field_comparisons' => array_map(
                    static fn ($c): array => [
                        'label' => $c->label,
                        'platform_value' => $c->platform_value,
                        'remote_value' => $c->remote_value,
                    ],
                    $subject->field_comparisons,
                ),
            ];
        }

        return $rows;
    }

    private function resolveEntityTrustAccount(User $user, Workspace $workspace): ?ConnectorAccount
    {
        $auth = app(AdobeProductsExportLiveAuthorizationService::class);

        if (! $auth->canAccessLive($user, $workspace)) {
            return null;
        }

        if (! $auth->isEligibleLiveTarget($user, $workspace, $this->accountId)) {
            return null;
        }

        return $auth->resolveConnectorAccount($user, $workspace, $this->accountId);
    }

    private function refreshEntityTrustPresentation(?User $user, ?Workspace $workspace): void
    {
        if (! $user instanceof User) {
            $this->resetEntityTrustPresentation();

            return;
        }

        $workspace ??= $this->resolveAdobeProductsExportExecutionWorkspace();

        $auth = app(AdobeProductsExportLiveAuthorizationService::class);
        $entityTrustAuth = app(AdobeProductEntityTrustAuthorizationService::class);

        $this->entityTrustSectionVisible = $auth->isEligibleLiveTarget($user, $workspace, $this->accountId);
        $this->entityTrustCanReviewOrConfirm = $this->entityTrustSectionVisible
            && $entityTrustAuth->canReviewOrConfirm($user, $workspace);

        if (! $this->entityTrustSectionVisible) {
            $this->entityTrustWorkingSet = [];

            return;
        }

        $account = $this->resolveEntityTrustAccount($user, $workspace);

        if ($account === null) {
            $this->entityTrustWorkingSet = [];

            return;
        }

        $this->entityTrustWorkingSet = app(EntityTrustMerchantReadService::class)
            ->workingSet($user, $workspace, $account);
    }

    private function resetEntityTrustPresentation(): void
    {
        $this->entityTrustWorkingSet = [];
        $this->entityTrustSectionVisible = false;
        $this->entityTrustCanReviewOrConfirm = false;
        $this->resetEntityTrustActiveReviewState();
    }

    private function resetEntityTrustActiveReviewState(): void
    {
        $this->entityTrustProductId = null;
        $this->entityTrustReviewFlowId = null;
        $this->entityTrustRelinkParentSku = null;
        $this->entityTrustInitialLinkParentSku = null;
        $this->entityTrustReviewIsConfigurable = false;

        $this->entityTrustOutcomeCategory = null;
        $this->entityTrustOutcomeLabel = null;
        $this->entityTrustOutcomeExplanation = null;
        $this->entityTrustOutcomeAction = null;
        $this->entityTrustOutcomeProductName = null;
        $this->entityTrustOutcomePrimarySku = null;
        $this->entityTrustOutcomeIsConfigurable = false;
        $this->entityTrustOutcomeAvailableAction = null;
        $this->entityTrustOutcomeIsConfirmation = false;
        $this->entityTrustOutcomeIsStale = false;
        $this->entityTrustOutcomeIsSuccess = false;
        $this->entityTrustOutcomeIsConflict = false;
        $this->entityTrustOutcomeIsSecurity = false;
        $this->entityTrustOutcomeIsRemoteFailure = false;
        $this->entityTrustOutcomeIsRelinkRequired = false;
        $this->entityTrustOutcomeReadyForConfirmation = false;

        $this->entityTrustActiveReviewFlowId = null;
        $this->entityTrustActiveReviewProductId = null;
        $this->entityTrustActiveSubjects = [];
        $this->entityTrustActiveExtraChildSkus = [];
        $this->entityTrustActiveExtraChildrenAvailable = false;
        $this->entityTrustActiveMode = null;

        $this->entityTrustErrorTitle = null;
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
