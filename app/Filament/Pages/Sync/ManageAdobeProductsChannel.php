<?php

namespace App\Filament\Pages\Sync;

use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Filament\Resources\ProductResource;
use App\Models\Category;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\SyncConfigurationProductSelection;
use App\Models\User;
use App\Services\Sync\AdobeProductExportSetupAuthorizationService;
use App\Services\Sync\AdobeProductsExportLiveAuthorizationService;
use App\Services\Sync\AdobeProductsExportPreviewAuthorizationService;
use App\Services\Sync\ProductChannelSelectionService;
use App\Services\Sync\SyncConfigurationLookupService;
use App\Services\Sync\SyncConfigurationReachabilityService;
use App\Services\Sync\SyncDataSetupLandingService;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspaceSyncDataSetupLandingPermission;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

class ManageAdobeProductsChannel extends Page implements HasTable
{
    use InteractsWithTable;
    use RequiresFreshWorkspaceSyncDataSetupLandingPermission;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'sync-data-setup/{account}/products/channel';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.pages.sync.manage-adobe-products-channel';

    #[Locked]
    public string $accountId;

    public string $platformName = '';

    public string $accountName = '';

    public ?string $configurationId = null;

    public int $selectedProductCount = 0;

    public int $masterProductCount = 0;

    public bool $canManageSelection = false;

    public bool $canRunPreview = false;

    public bool $canOpenExecution = false;

    public static function canAccess(array $parameters = []): bool
    {
        $user = Auth::user();
        $workspace = app(WorkspaceContext::class)->current();

        if (! $user instanceof User) {
            return false;
        }

        $accountId = $parameters['account'] ?? null;

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
        $workspace = $this->resolveSyncDataSetupLandingWorkspace();
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        abort_unless(
            app(SyncDataSetupLandingService::class)->canAccessChannelTarget($user, $workspace, $account),
            403,
        );

        $record = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('id', $account)
            ->with('connectorDefinition')
            ->firstOrFail();

        abort_unless($record->connectorDefinition?->code === 'adobe_commerce', 404);

        $this->accountId = (string) $record->id;
        $this->platformName = __('connectors.ui.layer_a.magento_name');
        $this->accountName = (string) $record->name;
        $this->refreshChannelState($user);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->selectedProductsQuery())
            ->columns([
                ImageColumn::make('channel_image')
                    ->label(__('product_channels.workbench.columns.image'))
                    ->state(fn (Product $record): ?string => ProductResource::firstImage($record))
                    ->size(44)
                    ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(44)))
                    ->extraImgAttributes(fn (Product $record): array => ProductResource::lightboxImgAttributes($record))
                    ->toggleable(),
                TextColumn::make('sku')
                    ->label(__('product_channels.workbench.columns.sku'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('name')
                    ->label(__('product_channels.workbench.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('is_linked')
                    ->label(__('product_channels.workbench.columns.link_status'))
                    ->formatStateUsing(fn (mixed $state): string => $state
                        ? __('product_channels.remote_catalog.link_status.linked')
                        : __('product_channels.workbench.publication.not_in_magento'))
                    ->badge()
                    ->sortable()
                    ->color(fn (mixed $state): string => $state ? 'success' : 'gray')
                    ->toggleable(),
                TextColumn::make('is_active')
                    ->label(__('product_channels.columns.status'))
                    ->formatStateUsing(fn (bool $state): string => $state
                        ? __('product_channels.product_status.active')
                        : __('product_channels.product_status.inactive'))
                    ->badge()
                    ->sortable()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('link_status')
                    ->label(__('product_channels.workbench.columns.link_status'))
                    ->options([
                        'linked' => __('product_channels.remote_catalog.link_status.linked'),
                        'unlinked' => __('product_channels.workbench.publication.not_in_magento'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $this->filterByLinkStatus(
                        $query,
                        is_string($data['value'] ?? null) ? $data['value'] : null,
                    )),
                SelectFilter::make('is_active')
                    ->label(__('product_channels.columns.status'))
                    ->options([
                        '1' => __('product_channels.product_status.active'),
                        '0' => __('product_channels.product_status.inactive'),
                    ]),
                SelectFilter::make('brand')
                    ->label(__('product_channels.workbench.columns.provider_brand'))
                    ->options(fn (): array => $this->publicationBrandFilterOptions()),
                SelectFilter::make('category_id')
                    ->label(__('product_channels.workbench.columns.category'))
                    ->options(fn (): array => $this->publicationCategoryFilterOptions()),
                SelectFilter::make('merchant_type')
                    ->label(__('product_channels.workbench.columns.product_type'))
                    ->options(fn (): array => $this->publicationProductTypeFilterOptions()),
            ])
            ->filtersLayout(FiltersLayout::Modal)
            ->filtersFormWidth('md')
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->button()
                    ->label(__('product_channels.workbench.toolbar.filters'))
                    ->tooltip(__('product_channels.workbench.toolbar.filters'))
                    ->extraAttributes(['class' => 'bp-workbench-toolbar-trigger bp-toolbar-count-trigger'])
                    ->slideOver()
            )
            ->columnManagerTriggerAction(
                fn (Action $action): Action => $action
                    ->button()
                    ->label(__('product_channels.workbench.toolbar.columns'))
                    ->tooltip(__('product_channels.workbench.toolbar.columns'))
                    ->badge(fn (): ?string => $this->visibleToggleableColumnCount())
                    ->extraAttributes(['class' => 'bp-workbench-toolbar-trigger bp-toolbar-count-trigger'])
                    ->slideOver()
            )
            ->searchPlaceholder(__('product_channels.workbench.search_placeholder'))
            ->recordUrl(null)
            ->recordAction('openProduct')
            ->recordActions([
                ViewAction::make('openProduct')
                    ->label(__('product_channels.workbench.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->iconButton()
                    ->tooltip(__('product_channels.workbench.actions.open'))
                    ->slideOver()
                    ->schema(fn (Schema $schema): Schema => ProductResource::infolist($schema))
                    ->extraModalFooterActions(fn (Product $record): array => [
                        Action::make('open_full_page_footer')
                            ->label(__('product_channels.workbench.actions.open_full'))
                            ->icon('heroicon-m-arrow-top-right-on-square')
                            ->color('gray')
                            ->url(ProductResource::getUrl('view', ['record' => $record])),
                    ]),
                Action::make('removeFromChannel')
                    ->label(__('product_channels.actions.remove_single'))
                    ->icon('heroicon-o-trash')
                    ->iconButton()
                    ->tooltip(__('product_channels.actions.remove_single'))
                    ->color('gray')
                    ->visible(fn (): bool => $this->canManageSelection)
                    ->requiresConfirmation()
                    ->action(fn (Product $record) => $this->removeProduct($record)),
            ])
            ->recordActionsColumnLabel(__('product_channels.workbench.columns.action'))
            ->recordActionsAlignment('start')
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

    public function selectProducts(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->resolveSyncDataSetupLandingWorkspace();

        abort_unless(
            app(ProductChannelSelectionService::class)->canManage($user, $workspace),
            403,
        );

        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('id', $this->accountId)
            ->firstOrFail();

        if ($this->configurationId === null) {
            abort_unless(
                app(AdobeProductExportSetupAuthorizationService::class)
                    ->isEligibleAdobeProductsExportSetupTarget($user, $workspace, $this->accountId),
                403,
            );

            $configuration = app(SyncConfigurationReachabilityService::class)
                ->ensureProductsExportConfiguration($account);
            $this->configurationId = (string) $configuration->id;
        }

        $this->redirect(ProductResource::getUrl('index', [
            'channel' => $this->configurationId,
        ]));
    }

    private function filterByLinkStatus(Builder $query, ?string $status): Builder
    {
        if (! in_array($status, ['linked', 'unlinked'], true)) {
            return $query;
        }

        $workspaceId = $this->resolveSyncDataSetupLandingWorkspace()->id;
        $linkedProductIds = ExternalRecordLink::withoutWorkspaceScope()
            ->select('product_id')
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $this->accountId)
            ->where('trust_origin', ExternalRecordLinkTrustOrigin::MerchantConfirmed->value)
            ->whereNotNull('external_record_discriminator')
            ->whereNotNull('established_by_workspace_user_id')
            ->whereNotNull('established_at');

        return $status === 'linked'
            ? $query->whereIn('products.id', $linkedProductIds)
            : $query->whereNotIn('products.id', $linkedProductIds);
    }

    /** @return array<string, string> */
    private function publicationBrandFilterOptions(): array
    {
        return $this->publicationFilterProductsQuery()
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand', 'brand')
            ->all();
    }

    /** @return array<string, string> */
    private function publicationCategoryFilterOptions(): array
    {
        $workspaceId = $this->resolveSyncDataSetupLandingWorkspace()->id;
        $categoryIds = $this->publicationFilterProductsQuery()
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->all();

        if ($categoryIds === []) {
            return [];
        }

        return Category::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $categoryIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, string> */
    private function publicationProductTypeFilterOptions(): array
    {
        return $this->publicationFilterProductsQuery()
            ->whereNotNull('merchant_type')
            ->where('merchant_type', '!=', '')
            ->distinct()
            ->orderBy('merchant_type')
            ->pluck('merchant_type', 'merchant_type')
            ->all();
    }

    private function publicationFilterProductsQuery(): Builder
    {
        $workspaceId = $this->resolveSyncDataSetupLandingWorkspace()->id;
        $query = Product::withoutWorkspaceScope()->where('workspace_id', $workspaceId);

        if ($this->configurationId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'products.id',
            SyncConfigurationProductSelection::withoutWorkspaceScope()
                ->select('product_id')
                ->where('workspace_id', $workspaceId)
                ->where('sync_configuration_id', $this->configurationId),
        );
    }

    private function removeProduct(Product $product): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        abort_unless($this->configurationId !== null, 404);

        $workspace = $this->resolveSyncDataSetupLandingWorkspace();

        app(ProductChannelSelectionService::class)->remove(
            $user,
            $workspace,
            $this->configurationId,
            [$product->id],
        );

        $this->refreshChannelState($user);

        Notification::make()
            ->success()
            ->title(__('product_channels.notifications.removed'))
            ->send();
    }

    private function selectedProductsQuery(): Builder
    {
        $workspaceId = $this->resolveSyncDataSetupLandingWorkspace()->id;
        $query = Product::withoutWorkspaceScope()->where('workspace_id', $workspaceId);

        if ($this->configurationId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->select('products.*')
            ->whereIn(
                'products.id',
                SyncConfigurationProductSelection::withoutWorkspaceScope()
                    ->select('product_id')
                    ->where('workspace_id', $workspaceId)
                    ->where('sync_configuration_id', $this->configurationId),
            )
            ->addSelect([
                'is_linked' => ExternalRecordLink::withoutWorkspaceScope()
                    ->selectRaw('COUNT(*) > 0')
                    ->where('workspace_id', $workspaceId)
                    ->where('connector_account_id', $this->accountId)
                    ->where('trust_origin', ExternalRecordLinkTrustOrigin::MerchantConfirmed->value)
                    ->whereNotNull('external_record_discriminator')
                    ->whereNotNull('established_by_workspace_user_id')
                    ->whereNotNull('established_at')
                    ->whereColumn('product_id', 'products.id')
                    ->limit(1),
            ]);
    }

    private function refreshChannelState(User $user): void
    {
        $workspace = $this->resolveSyncDataSetupLandingWorkspace();

        abort_unless(
            app(SyncDataSetupLandingService::class)->canAccessChannelTarget($user, $workspace, $this->accountId),
            403,
        );

        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('id', $this->accountId)
            ->firstOrFail();

        $configuration = app(SyncConfigurationLookupService::class)
            ->findProductsDefaultContext($account);

        $this->configurationId = $configuration?->id;
        $this->selectedProductCount = $configuration === null
            ? 0
            : SyncConfigurationProductSelection::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('sync_configuration_id', $configuration->id)
                ->count();
        $this->masterProductCount = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->count();
        $this->canManageSelection = app(ProductChannelSelectionService::class)
            ->canManage($user, $workspace);
        $this->canRunPreview = $configuration !== null
            && app(AdobeProductsExportPreviewAuthorizationService::class)
                ->isEligiblePreviewTarget($user, $workspace, $this->accountId);
        $this->canOpenExecution = $configuration !== null
            && ($this->canRunPreview
                || app(AdobeProductsExportLiveAuthorizationService::class)
                    ->isEligibleLiveTarget($user, $workspace, $this->accountId));
    }
}
