<?php

namespace App\Filament\Pages\Sync;

use App\Filament\Resources\ProductResource;
use App\Models\AdobeProductAttributeSet;
use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\RemoteCatalogSnapshot;
use App\Models\RemoteCatalogSnapshotItem;
use App\Models\RemoteCatalogSnapshotItemCategory;
use App\Models\User;
use App\Services\Connectors\AdobeRemoteCatalogProjectionService;
use App\Services\Connectors\AdobeRemoteCatalogScanDispatchService;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustAuthorizationService;
use App\Services\Sync\EntityTrust\AdobeRemoteCatalogEntityTrustService;
use App\Services\Sync\EntityTrust\EntityTrustFailureReasonPresenter;
use App\Services\Sync\EntityTrust\EntityTrustMerchantOrchestrator;
use App\Services\Sync\SyncDataSetupLandingService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Sync\EntityTrust\EntityTrustMerchantOutcome;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspaceSyncDataSetupLandingPermission;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
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

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.pages.sync.manage-adobe-remote-catalog';

    #[Locked]
    public string $accountId;

    #[Locked]
    public ?string $snapshotId = null;

    public string $platformName = '';

    public string $accountName = '';

    #[Locked]
    public string $accountBaseUrl = '';

    public bool $canRefreshRemoteCatalog = false;

    public bool $remoteCatalogScanRunning = false;

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
        return __('product_channels.workbench.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function mount(string $account): void
    {
        $record = $this->resolveAccount($account);

        $this->accountId = (string) $record->id;
        $this->platformName = __('connectors.ui.layer_a.magento_name');
        $this->accountName = (string) $record->name;
        $this->accountBaseUrl = (string) $record->base_url;
        $this->refreshProjectionState($record);
    }

    public function hydrate(): void
    {
        $record = $this->resolveAccount($this->accountId);
        $this->accountName = (string) $record->name;
        $this->accountBaseUrl = (string) $record->base_url;
        $this->refreshProjectionState($record);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->catalogItemsQuery())
            ->columns([
                ImageColumn::make('thumbnail_locator')
                    ->label(__('product_channels.workbench.columns.image'))
                    ->state(fn (RemoteCatalogSnapshotItem $record): ?string => $this->thumbnailUrl($record))
                    ->size(44)
                    ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(44)))
                    ->extraImgAttributes(fn (RemoteCatalogSnapshotItem $record): array => $this->remoteLightboxImgAttributes($record))
                    ->toggleable(),
                TextColumn::make('sku')
                    ->label(__('product_channels.workbench.columns.sku'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('name')
                    ->label(__('product_channels.workbench.columns.name'))
                    ->searchable(['name', 'remote_identifier'])
                    ->sortable()
                    ->wrap(),
                TextColumn::make('provider_brand_label')
                    ->label(__('product_channels.workbench.columns.provider_brand'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('category_paths')
                    ->label(__('product_channels.workbench.columns.category'))
                    ->state(fn (RemoteCatalogSnapshotItem $record): ?string => $this->categoryPaths($record))
                    ->placeholder('—')
                    ->wrap()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        RemoteCatalogSnapshotItemCategory::withoutWorkspaceScope()
                            ->select('category_path')
                            ->whereColumn('snapshot_item_id', 'remote_catalog_snapshot_items.id')
                            ->whereNotNull('category_path')
                            ->orderBy('category_path')
                            ->limit(1),
                        $direction,
                    ))
                    ->toggleable(),
                TextColumn::make('attribute_set_name')
                    ->label(__('product_channels.workbench.columns.attribute_set'))
                    ->placeholder('—')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('external_attribute_set_id', $direction))
                    ->toggleable(),
                TextColumn::make('remote_type')
                    ->label(__('product_channels.workbench.columns.type'))
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('remote_status')
                    ->label(__('product_channels.workbench.columns.magento_status'))
                    ->formatStateUsing(fn (mixed $state): string => $this->remoteStatusLabel($state))
                    ->badge()
                    ->sortable()
                    ->color(fn (mixed $state): string => (string) $state === '1' ? 'success' : ((string) $state === '2' ? 'gray' : 'warning')),
                TextColumn::make('is_linked')
                    ->label(__('product_channels.workbench.columns.link_status'))
                    ->formatStateUsing(fn (mixed $state): string => $state
                        ? __('product_channels.remote_catalog.link_status.linked')
                        : __('product_channels.remote_catalog.link_status.unlinked'))
                    ->badge()
                    ->sortable()
                    ->color(fn (mixed $state): string => $state ? 'success' : 'warning'),
                TextColumn::make('remote_updated_at')
                    ->label(__('product_channels.workbench.columns.updated'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('link_status')
                    ->label(__('product_channels.workbench.columns.link_status'))
                    ->options([
                        'linked' => __('product_channels.remote_catalog.link_status.linked'),
                        'unlinked' => __('product_channels.remote_catalog.link_status.unlinked'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['value'] ?? null;

                        return app(AdobeRemoteCatalogProjectionService::class)->filterItemsByLinkStatus(
                            $query,
                            $this->resolveAccount($this->accountId),
                            is_string($status) ? $status : null,
                        );
                    }),
                SelectFilter::make('provider_brand_label')
                    ->label(__('product_channels.workbench.columns.provider_brand'))
                    ->options(fn (): array => $this->brandFilterOptions()),
                SelectFilter::make('remote_type')
                    ->label(__('product_channels.workbench.columns.type'))
                    ->options(fn (): array => $this->remoteTypeFilterOptions()),
                SelectFilter::make('remote_status')
                    ->label(__('product_channels.workbench.columns.magento_status'))
                    ->options([
                        '1' => __('product_channels.workbench.magento_status.enabled'),
                        '2' => __('product_channels.workbench.magento_status.disabled'),
                    ]),
                SelectFilter::make('external_attribute_set_id')
                    ->label(__('product_channels.workbench.columns.attribute_set'))
                    ->options(fn (): array => $this->attributeSetFilterOptions()),
                SelectFilter::make('category')
                    ->label(__('product_channels.workbench.columns.category'))
                    ->options(fn (): array => $this->categoryFilterOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $categoryId = $data['value'] ?? null;

                        if (! is_string($categoryId) || $categoryId === '') {
                            return $query;
                        }

                        return $query->whereHas(
                            'categories',
                            fn (Builder $categories): Builder => $categories
                                ->withoutGlobalScopes()
                                ->where('external_category_id', $categoryId),
                        );
                    }),
            ])
            ->filtersLayout(FiltersLayout::Modal)
            ->filtersFormWidth('md')
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->button()
                    ->label(__('product_channels.workbench.toolbar.filters'))
                    ->tooltip(__('product_channels.workbench.toolbar.filters'))
                    ->extraAttributes(['class' => 'bp-workbench-toolbar-trigger'])
                    ->slideOver()
            )
            ->columnManagerTriggerAction(
                fn (Action $action): Action => $action
                    ->button()
                    ->label(__('product_channels.workbench.toolbar.columns'))
                    ->tooltip(__('product_channels.workbench.toolbar.columns'))
                    ->badge(fn (): ?string => $this->visibleToggleableColumnCount())
                    ->extraAttributes(['class' => 'bp-workbench-toolbar-trigger'])
                    ->slideOver()
            )
            ->searchPlaceholder(__('product_channels.workbench.search_placeholder'))
            ->recordUrl(null)
            ->recordAction('viewRemoteProduct')
            ->recordActions([
                ViewAction::make('viewRemoteProduct')
                    ->label(__('product_channels.workbench.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->iconButton()
                    ->tooltip(__('product_channels.workbench.actions.open'))
                    ->slideOver()
                    ->modalHeading(fn (RemoteCatalogSnapshotItem $record): string => $record->name ?: $record->sku ?: $record->remote_identifier)
                    ->schema(fn (): array => $this->remoteDetailSchema())
                    ->extraModalFooterActions(fn (RemoteCatalogSnapshotItem $record): array => $this->remoteFullProductFooterActions($record)),
                Action::make('linkMasterProduct')
                    ->label(__('product_channels.workbench.actions.link'))
                    ->icon('heroicon-o-link')
                    ->iconButton()
                    ->tooltip(__('product_channels.workbench.actions.link'))
                    ->visible(fn (RemoteCatalogSnapshotItem $record): bool => $this->canReviewOrConfirm() && ! (bool) $record->getAttribute('is_linked'))
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
            ->recordActionsColumnLabel(__('product_channels.workbench.columns.action'))
            ->paginated([20, 50, 100])
            ->defaultPaginationPageOption(20)
            ->defaultSort('name');
    }

    private function visibleToggleableColumnCount(): ?string
    {
        $count = 0;

        foreach ($this->tableColumns as $item) {
            if (($item['type'] ?? null) === self::TABLE_COLUMN_MANAGER_COLUMN_TYPE) {
                if (($item['isToggleable'] ?? false) && ($item['isToggled'] ?? false) && ! ($item['isHidden'] ?? false)) {
                    $count++;
                }

                continue;
            }

            foreach ($item['columns'] ?? [] as $column) {
                if (($column['isToggleable'] ?? false) && ($column['isToggled'] ?? false) && ! ($column['isHidden'] ?? false)) {
                    $count++;
                }
            }
        }

        return $count > 0 ? (string) $count : null;
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

    public function refreshRemoteCatalog(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->resolveSyncDataSetupLandingWorkspace();

        app(AdobeRemoteCatalogScanDispatchService::class)->dispatch(
            $user,
            $workspace,
            $this->accountId,
        );

        $this->remoteCatalogScanRunning = true;

        Notification::make()
            ->success()
            ->title(__('product_channels.remote_catalog.scan_queued'))
            ->send();
    }

    /** @return array<int, mixed> */
    private function remoteDetailSchema(): array
    {
        return [
            Section::make(__('product_channels.workbench.tabs.overview'))
                ->schema([
                    TextEntry::make('sku')
                        ->label(__('product_channels.workbench.columns.sku'))
                        ->placeholder('—'),
                    TextEntry::make('name')
                        ->label(__('product_channels.workbench.columns.name'))
                        ->placeholder('—'),
                    TextEntry::make('provider_brand_label')
                        ->label(__('product_channels.workbench.columns.provider_brand'))
                        ->placeholder('—'),
                    TextEntry::make('category_paths')
                        ->label(__('product_channels.workbench.columns.category'))
                        ->state(fn (RemoteCatalogSnapshotItem $record): ?string => $this->categoryPaths($record))
                        ->placeholder('—'),
                    TextEntry::make('attribute_set_name')
                        ->label(__('product_channels.workbench.columns.attribute_set'))
                        ->placeholder('—'),
                    TextEntry::make('remote_type')
                        ->label(__('product_channels.workbench.columns.type'))
                        ->placeholder('—'),
                    TextEntry::make('remote_status')
                        ->label(__('product_channels.workbench.columns.magento_status'))
                        ->formatStateUsing(fn (mixed $state): string => $this->remoteStatusLabel($state))
                        ->badge(),
                    TextEntry::make('is_linked')
                        ->label(__('product_channels.workbench.columns.link_status'))
                        ->formatStateUsing(fn (mixed $state): string => $state
                            ? __('product_channels.remote_catalog.link_status.linked')
                            : __('product_channels.remote_catalog.link_status.unlinked'))
                        ->badge()
                        ->color(fn (mixed $state): string => $state ? 'success' : 'warning'),
                    TextEntry::make('remote_updated_at')
                        ->label(__('product_channels.workbench.columns.updated'))
                        ->dateTime()
                        ->placeholder('—'),
                ])
                ->columns(2),
        ];
    }

    /** @return array<string, Action> */
    private function remoteFullProductFooterActions(RemoteCatalogSnapshotItem $record): array
    {
        $linkedProductId = $record->getAttribute('linked_product_id');

        if (! filled($linkedProductId)) {
            return [];
        }

        return [
            'open_full_page_footer' => Action::make('open_full_page_footer')
                ->label(__('product_channels.workbench.actions.open_full'))
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->url(ProductResource::getUrl('view', ['record' => $linkedProductId])),
        ];
    }

    private function thumbnailUrl(RemoteCatalogSnapshotItem $record): ?string
    {
        $locator = trim((string) $record->thumbnail_locator);

        if ($locator === '') {
            return null;
        }

        if (filter_var($locator, FILTER_VALIDATE_URL) !== false) {
            return $locator;
        }

        $baseUrl = rtrim($this->accountBaseUrl, '/');

        if ($baseUrl === '') {
            return null;
        }

        return $baseUrl.'/media/catalog/product/'.ltrim($locator, '/');
    }

    /** @return array<string, string> */
    private function remoteLightboxImgAttributes(RemoteCatalogSnapshotItem $record): array
    {
        $url = $this->thumbnailUrl($record);

        if ($url === null) {
            return [
                'class' => 'rounded object-cover',
                'style' => 'cursor: default;',
            ];
        }

        $safe = e($url);
        $title = e($record->name ?: $record->sku ?: $record->remote_identifier);

        return [
            'class' => 'rounded object-cover',
            'style' => 'cursor: zoom-in;',
            'title' => __('product_channels.workbench.image_zoom'),
            'onclick' => "event.stopPropagation();event.preventDefault();bpOpenLightbox('{$safe}','{$title}')",
        ];
    }

    private function categoryPaths(RemoteCatalogSnapshotItem $record): ?string
    {
        $paths = $record->categories
            ->pluck('category_path')
            ->filter(static fn (mixed $path): bool => is_string($path) && trim($path) !== '')
            ->unique()
            ->values();

        return $paths->isEmpty() ? null : $paths->implode(' · ');
    }

    private function remoteStatusLabel(mixed $state): string
    {
        return match ((string) $state) {
            '1' => __('product_channels.workbench.magento_status.enabled'),
            '2' => __('product_channels.workbench.magento_status.disabled'),
            default => __('product_channels.workbench.magento_status.unknown'),
        };
    }

    /** @return array<string, string> */
    private function brandFilterOptions(): array
    {
        if ($this->snapshotId === null) {
            return [];
        }

        return RemoteCatalogSnapshotItem::withoutWorkspaceScope()
            ->where('workspace_id', $this->resolveSyncDataSetupLandingWorkspace()->id)
            ->where('snapshot_id', $this->snapshotId)
            ->whereNotNull('provider_brand_label')
            ->where('provider_brand_label', '!=', '')
            ->distinct()
            ->orderBy('provider_brand_label')
            ->pluck('provider_brand_label', 'provider_brand_label')
            ->all();
    }

    /** @return array<string, string> */
    private function remoteTypeFilterOptions(): array
    {
        if ($this->snapshotId === null) {
            return [];
        }

        return RemoteCatalogSnapshotItem::withoutWorkspaceScope()
            ->where('workspace_id', $this->resolveSyncDataSetupLandingWorkspace()->id)
            ->where('snapshot_id', $this->snapshotId)
            ->whereNotNull('remote_type')
            ->where('remote_type', '!=', '')
            ->distinct()
            ->orderBy('remote_type')
            ->pluck('remote_type', 'remote_type')
            ->all();
    }

    /** @return array<int|string, string> */
    private function attributeSetFilterOptions(): array
    {
        if ($this->snapshotId === null) {
            return [];
        }

        $workspace = $this->resolveSyncDataSetupLandingWorkspace();
        $attributeSetIds = RemoteCatalogSnapshotItem::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('snapshot_id', $this->snapshotId)
            ->whereNotNull('external_attribute_set_id')
            ->distinct()
            ->orderBy('external_attribute_set_id')
            ->pluck('external_attribute_set_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->values();

        if ($attributeSetIds->isEmpty()) {
            return [];
        }

        $labels = AdobeProductAttributeSet::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('connector_account_id', $this->accountId)
            ->whereNull('missing_since')
            ->whereIn('provider_attribute_set_id', $attributeSetIds->all())
            ->pluck('name', 'provider_attribute_set_id')
            ->all();

        $options = [];
        foreach ($attributeSetIds as $attributeSetId) {
            $options[$attributeSetId] = $labels[$attributeSetId] ?? '#'.$attributeSetId;
        }

        return $options;
    }

    /** @return array<string, string> */
    private function categoryFilterOptions(): array
    {
        if ($this->snapshotId === null) {
            return [];
        }

        $workspaceId = $this->resolveSyncDataSetupLandingWorkspace()->id;
        $itemIds = RemoteCatalogSnapshotItem::withoutWorkspaceScope()
            ->select('id')
            ->where('workspace_id', $workspaceId)
            ->where('snapshot_id', $this->snapshotId);

        return RemoteCatalogSnapshotItemCategory::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('snapshot_item_id', $itemIds)
            ->whereNotNull('category_path')
            ->where('category_path', '!=', '')
            ->orderBy('category_path')
            ->pluck('category_path', 'external_category_id')
            ->all();
    }

    private function catalogItemsQuery(): Builder
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
            ->itemsQuery($account, $snapshot);
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
        $this->capturedAt = $summary->snapshot?->captured_at?->format('d.m.Y H:i');
        $this->remoteCatalogScanRunning = $summary->scanRunning;
        $user = Auth::user();
        $this->canRefreshRemoteCatalog = $user instanceof User
            && app(WorkspaceAuthorization::class)->allows(
                $user,
                $this->resolveSyncDataSetupLandingWorkspace(),
                WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            );
        $this->entityTrustCanReviewOrConfirm = $this->canReviewOrConfirm();
    }
}
