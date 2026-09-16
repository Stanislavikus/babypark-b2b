<?php

namespace App\Filament\Pages\Sync;

use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\RemoteCatalogSnapshot;
use App\Models\RemoteCatalogSnapshotItem;
use App\Models\User;
use App\Services\Connectors\AdobeRemoteCatalogProjectionService;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustAuthorizationService;
use App\Services\Sync\EntityTrust\AdobeRemoteCatalogEntityTrustService;
use App\Services\Sync\EntityTrust\EntityTrustFailureReasonPresenter;
use App\Services\Sync\EntityTrust\EntityTrustMerchantOrchestrator;
use App\Services\Sync\SyncDataSetupLandingService;
use App\Support\Sync\EntityTrust\EntityTrustMerchantOutcome;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspaceSyncDataSetupLandingPermission;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

class ManageAdobeRemoteCatalog extends Page implements HasTable
{
    use InteractsWithTable;
    use RequiresFreshWorkspaceSyncDataSetupLandingPermission;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'sync-data-setup/{account}/products/remote-catalog';

    protected string $view = 'filament.pages.sync.manage-adobe-remote-catalog';

    #[Locked]
    public string $accountId;

    #[Locked]
    public ?string $snapshotId = null;

    public string $platformName = '';

    public string $accountName = '';

    public int $remoteCatalogTotal = 0;

    public int $linkedRemoteCount = 0;

    public int $remoteOnlyCount = 0;

    public ?string $capturedAt = null;

    public bool $entityTrustSectionVisible = false;

    public bool $entityTrustCanReviewOrConfirm = false;

    #[Locked]
    public ?string $entityTrustProductId = null;

    #[Locked]
    public ?string $entityTrustReviewFlowId = null;

    #[Locked]
    public ?string $entityTrustRemoteItemId = null;

    public ?string $entityTrustOutcomeCategory = null;

    public ?string $entityTrustOutcomeLabel = null;

    public ?string $entityTrustOutcomeExplanation = null;

    public ?string $entityTrustOutcomeProductName = null;

    public ?string $entityTrustOutcomePrimarySku = null;

    public bool $entityTrustOutcomeReadyForConfirmation = false;

    public ?string $entityTrustActiveReviewFlowId = null;

    public ?string $entityTrustActiveReviewProductId = null;

    public ?string $entityTrustActiveMode = null;

    /** @var list<array<string, mixed>> */
    public array $entityTrustActiveSubjects = [];

    /** @var list<string> */
    public array $entityTrustActiveExtraChildSkus = [];

    public bool $entityTrustActiveExtraChildrenAvailable = false;

    public ?string $entityTrustErrorTitle = null;

    public static function canAccess(array $parameters = []): bool
    {
        $user = Auth::user();
        $workspace = app(WorkspaceContext::class)->current();
        $accountId = $parameters['account'] ?? null;

        if (! $user instanceof User) {
            return false;
        }

        return is_string($accountId) && $accountId !== ''
            ? app(SyncDataSetupLandingService::class)->canAccessChannelTarget($user, $workspace, $accountId)
            : app(SyncDataSetupLandingService::class)->canAccessLanding($user, $workspace);
    }

    public function getTitle(): string|Htmlable
    {
        return __('product_channels.remote_catalog.title', [
            'platform' => $this->platformName,
            'account' => $this->accountName,
        ]);
    }

    public function mount(string $account): void
    {
        $record = $this->resolveAccount($account);

        $this->accountId = (string) $record->id;
        $this->platformName = __('connectors.ui.layer_a.magento_name');
        $this->accountName = (string) $record->name;
        $this->refreshProjectionState($record);
    }

    public function hydrate(): void
    {
        $this->refreshProjectionState($this->resolveAccount($this->accountId));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->remoteOnlyQuery())
            ->heading(__('product_channels.remote_catalog.table_heading'))
            ->description(__('product_channels.remote_catalog.table_description'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('product_channels.remote_catalog.columns.product'))
                    ->searchable(['name', 'sku', 'remote_identifier'])
                    ->sortable()
                    ->description(fn (RemoteCatalogSnapshotItem $record): string => __('product_channels.remote_catalog.identity', [
                        'sku' => $record->sku ?: '—',
                        'id' => $record->remote_identifier,
                    ])),
                TextColumn::make('remote_type')
                    ->label(__('product_channels.remote_catalog.columns.type'))
                    ->badge(),
                TextColumn::make('remote_status')
                    ->label(__('product_channels.remote_catalog.columns.status'))
                    ->badge(),
                TextColumn::make('remote_updated_at')
                    ->label(__('product_channels.remote_catalog.columns.updated'))
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('linkMasterProduct')
                    ->label(__('product_channels.remote_catalog.link.action'))
                    ->icon('heroicon-o-link')
                    ->visible(fn (): bool => $this->canReviewOrConfirm())
                    ->modalHeading(__('product_channels.remote_catalog.link.heading'))
                    ->modalDescription(fn (RemoteCatalogSnapshotItem $record): string => __(
                        'product_channels.remote_catalog.link.description',
                        ['name' => $record->name ?: $record->sku ?: $record->remote_identifier],
                    ))
                    ->modalSubmitActionLabel(__('product_channels.remote_catalog.link.review'))
                    ->schema(fn (RemoteCatalogSnapshotItem $record): array => [
                        Select::make('product_id')
                            ->label(__('product_channels.remote_catalog.link.product_label'))
                            ->options(fn (): array => $this->candidateProductOptions($record, null))
                            ->searchable()
                            ->getSearchResultsUsing(fn (?string $search): array => $this->candidateProductOptions($record, $search))
                            ->getOptionLabelUsing(fn (?string $value): ?string => $this->candidateProductLabel($value))
                            ->required(),
                    ])
                    ->action(function (RemoteCatalogSnapshotItem $record, array $data): void {
                        $this->startEntityTrustReview($record, (string) ($data['product_id'] ?? ''));
                    }),
            ])
            ->paginated([20, 50, 100])
            ->defaultPaginationPageOption(20)
            ->defaultSort('name');
    }

    public function confirmEntityTrust(): void
    {
        $flowId = $this->entityTrustReviewFlowId;
        $productId = $this->entityTrustProductId;

        if ($flowId === null || $productId === null) {
            $this->resetEntityTrustReviewState();

            return;
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->resolveSyncDataSetupLandingWorkspace();
        $account = $this->resolveAccount($this->accountId);
        $previousFlowId = $flowId;

        try {
            $outcome = app(AdobeRemoteCatalogEntityTrustService::class)->confirm(
                $user,
                $workspace,
                $account->id,
                $productId,
                $flowId,
            );
            $this->applyEntityTrustOutcome($outcome, $previousFlowId);
            $this->refreshProjectionState($account->fresh());
        } catch (AuthorizationException) {
            abort(403);
        } catch (EntityTrustException $exception) {
            $this->applyEntityTrustFailure($exception, $previousFlowId);
        }
    }

    public function cancelEntityTrustFlow(): void
    {
        if ($this->entityTrustReviewFlowId !== null) {
            app(EntityTrustMerchantOrchestrator::class)->cancelFlow($this->entityTrustReviewFlowId);
        }

        $this->resetEntityTrustReviewState(clearOutcome: true);
    }

    private function startEntityTrustReview(RemoteCatalogSnapshotItem $record, string $productId): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->resolveSyncDataSetupLandingWorkspace();
        $account = $this->resolveAccount($this->accountId);
        $previousFlowId = $this->entityTrustReviewFlowId;

        try {
            $outcome = app(AdobeRemoteCatalogEntityTrustService::class)->requestReview(
                $user,
                $workspace,
                $account->id,
                (string) $record->id,
                $productId,
            );
            $this->entityTrustRemoteItemId = (string) $record->id;
            $this->applyEntityTrustOutcome($outcome, $previousFlowId);
        } catch (AuthorizationException) {
            abort(403);
        } catch (EntityTrustException $exception) {
            $this->applyEntityTrustFailure($exception, $previousFlowId);
        }
    }

    /** @return array<string, string> */
    private function candidateProductOptions(RemoteCatalogSnapshotItem $record, ?string $search): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        try {
            return collect(app(AdobeRemoteCatalogEntityTrustService::class)->candidateProducts(
                $user,
                $this->resolveSyncDataSetupLandingWorkspace(),
                $this->accountId,
                (string) $record->id,
                $search,
            ))->mapWithKeys(static fn (array $row): array => [$row['id'] => $row['label']])->all();
        } catch (AuthorizationException|EntityTrustException) {
            return [];
        }
    }

    private function candidateProductLabel(?string $productId): ?string
    {
        if ($productId === null || $productId === '') {
            return null;
        }

        $workspace = $this->resolveSyncDataSetupLandingWorkspace();
        $product = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->whereKey($productId)
            ->first(['id', 'name', 'sku']);

        if (! $product instanceof Product) {
            return null;
        }

        return trim((string) $product->name).' · '.((string) $product->sku !== '' ? $product->sku : '—');
    }

    private function canReviewOrConfirm(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && app(AdobeProductEntityTrustAuthorizationService::class)->canReviewOrConfirm(
                $user,
                $this->resolveSyncDataSetupLandingWorkspace(),
            );
    }

    private function applyEntityTrustOutcome(EntityTrustMerchantOutcome $outcome, ?string $previousFlowId): void
    {
        if ($previousFlowId !== null && $previousFlowId !== $outcome->review_flow_id) {
            app(EntityTrustMerchantOrchestrator::class)->cancelFlow($previousFlowId);
        }

        $this->entityTrustSectionVisible = true;
        $this->entityTrustOutcomeCategory = $outcome->category->value;
        $this->entityTrustOutcomeLabel = __($outcome->label_key);
        $this->entityTrustOutcomeExplanation = __($outcome->explanation_key);
        $this->entityTrustOutcomeProductName = $outcome->productName;
        $this->entityTrustOutcomePrimarySku = $outcome->primary_sku;
        $this->entityTrustOutcomeReadyForConfirmation = $outcome->review_flow_id !== null;
        $this->entityTrustReviewFlowId = $outcome->review_flow_id;
        $this->entityTrustProductId = $outcome->review_flow_id !== null ? $outcome->product_id : null;
        $this->entityTrustActiveReviewFlowId = $outcome->review_flow_id;
        $this->entityTrustActiveReviewProductId = $outcome->review_flow_id !== null ? $outcome->product_id : null;
        $this->entityTrustActiveMode = $outcome->review_flow_id !== null
            ? ($outcome->is_configurable_family
                ? __('entity_trust.mode.configurable_existing_parent')
                : __('entity_trust.mode.simple_variant'))
            : null;
        $this->entityTrustActiveSubjects = $this->presentEntityTrustSubjects($outcome);
        $this->entityTrustActiveExtraChildSkus = $outcome->extra_remote_child_skus;
        $this->entityTrustActiveExtraChildrenAvailable = $outcome->extra_remote_children_available;
        $this->entityTrustErrorTitle = null;
    }

    private function applyEntityTrustFailure(EntityTrustException $exception, ?string $previousFlowId): void
    {
        if ($previousFlowId !== null) {
            app(EntityTrustMerchantOrchestrator::class)->cancelFlow($previousFlowId);
        }

        $presentation = app(EntityTrustFailureReasonPresenter::class)->present($exception->reason);
        $this->resetEntityTrustReviewState();
        $this->entityTrustSectionVisible = true;
        $this->entityTrustOutcomeCategory = $presentation['category']->value;
        $this->entityTrustOutcomeLabel = __($presentation['label_key']);
        $this->entityTrustOutcomeExplanation = __($presentation['explanation_key']);
    }

    /** @return list<array<string, mixed>> */
    private function presentEntityTrustSubjects(EntityTrustMerchantOutcome $outcome): array
    {
        return array_map(static fn ($subject): array => [
            'role' => $subject->role,
            'expected_sku' => $subject->expected_sku,
            'magento_type_label' => __($subject->magento_type_label),
            'platform_name' => $subject->platform_name,
            'declared_image_count' => $subject->declared_image_count,
            'declared_roles_summary' => $subject->declared_roles_summary,
            'field_comparisons' => array_map(static fn ($comparison): array => [
                'label' => $comparison->label,
                'platform_value' => $comparison->platform_value,
                'remote_value' => $comparison->remote_value,
            ], $subject->field_comparisons),
        ], $outcome->subjects);
    }

    private function resetEntityTrustReviewState(bool $clearOutcome = false): void
    {
        $this->entityTrustProductId = null;
        $this->entityTrustReviewFlowId = null;
        $this->entityTrustRemoteItemId = null;
        $this->entityTrustOutcomeReadyForConfirmation = false;
        $this->entityTrustActiveReviewFlowId = null;
        $this->entityTrustActiveReviewProductId = null;
        $this->entityTrustActiveMode = null;
        $this->entityTrustActiveSubjects = [];
        $this->entityTrustActiveExtraChildSkus = [];
        $this->entityTrustActiveExtraChildrenAvailable = false;
        $this->entityTrustErrorTitle = null;

        if ($clearOutcome) {
            $this->entityTrustSectionVisible = false;
            $this->entityTrustOutcomeCategory = null;
            $this->entityTrustOutcomeLabel = null;
            $this->entityTrustOutcomeExplanation = null;
            $this->entityTrustOutcomeProductName = null;
            $this->entityTrustOutcomePrimarySku = null;
        }
    }

    private function remoteOnlyQuery(): Builder
    {
        $workspace = $this->resolveSyncDataSetupLandingWorkspace();
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($this->accountId)
            ->firstOrFail();

        $snapshot = $this->snapshotId === null
            ? null
            : RemoteCatalogSnapshot::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('connector_account_id', $account->id)
                ->whereKey($this->snapshotId)
                ->first();

        return app(AdobeRemoteCatalogProjectionService::class)
            ->remoteOnlyItemsQuery($account, $snapshot);
    }

    private function resolveAccount(string $accountId): ConnectorAccount
    {
        $workspace = $this->resolveSyncDataSetupLandingWorkspace();
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        abort_unless(
            app(SyncDataSetupLandingService::class)->canAccessChannelTarget($user, $workspace, $accountId),
            403,
        );

        $record = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($accountId)
            ->with('connectorDefinition')
            ->firstOrFail();
        abort_unless($record->connectorDefinition?->code === 'adobe_commerce', 404);

        return $record;
    }

    private function refreshProjectionState(ConnectorAccount $account): void
    {
        $summary = app(AdobeRemoteCatalogProjectionService::class)->summary($account);

        $this->snapshotId = $summary->snapshot?->id;
        $this->remoteCatalogTotal = $summary->totalCount;
        $this->linkedRemoteCount = $summary->linkedCount;
        $this->remoteOnlyCount = $summary->remoteOnlyCount;
        $this->capturedAt = $summary->snapshot?->captured_at?->toIso8601String();
        $this->entityTrustCanReviewOrConfirm = $this->canReviewOrConfirm();
    }
}
