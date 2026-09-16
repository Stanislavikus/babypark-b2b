<?php

namespace App\Filament\Pages\Sync;

use App\Filament\Resources\ProductResource;
use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\SyncConfigurationProductSelection;
use App\Models\User;
use App\Services\Sync\AdobeProductExportSetupAuthorizationService;
use App\Services\Sync\AdobeProductsExportPreviewAuthorizationService;
use App\Services\Sync\ProductChannelSelectionService;
use App\Services\Sync\SyncConfigurationLookupService;
use App\Services\Sync\SyncConfigurationReachabilityService;
use App\Services\Sync\SyncDataSetupLandingService;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspaceSyncDataSetupLandingPermission;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
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
        return __('product_channels.channel.title', [
            'platform' => $this->platformName,
            'account' => $this->accountName,
        ]);
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
            ->heading(__('product_channels.channel.products_heading'))
            ->description(__('product_channels.channel.products_description'))
            ->columns([
                ImageColumn::make('channel_image')
                    ->label(__('product_channels.columns.image'))
                    ->state(fn (Product $record): ?string => ProductResource::firstImage($record))
                    ->size(48)
                    ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(ProductResource::placeholderSvg(48))),
                TextColumn::make('name')
                    ->label(__('product_channels.columns.product'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Product $record): string => $this->productIdentityDescription($record)),
                TextColumn::make('is_active')
                    ->label(__('product_channels.columns.status'))
                    ->formatStateUsing(fn (bool $state): string => $state
                        ? __('product_channels.product_status.active')
                        : __('product_channels.product_status.inactive'))
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('openProduct')
                    ->label(__('product_channels.actions.open_product'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(),
                Action::make('removeFromChannel')
                    ->label(__('product_channels.actions.remove_single'))
                    ->icon('heroicon-o-minus-circle')
                    ->color('gray')
                    ->visible(fn (): bool => $this->canManageSelection)
                    ->requiresConfirmation()
                    ->action(fn (Product $record) => $this->removeProduct($record)),
            ])
            ->paginated([20, 50, 100])
            ->defaultPaginationPageOption(20)
            ->defaultSort('name');
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

        return $query->whereIn(
            'products.id',
            SyncConfigurationProductSelection::withoutWorkspaceScope()
                ->select('product_id')
                ->where('workspace_id', $workspaceId)
                ->where('sync_configuration_id', $this->configurationId),
        );
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
    }

    private function productIdentityDescription(Product $product): string
    {
        $parts = [__('product_channels.identity.sku', ['sku' => $product->sku ?: '—'])];

        if (filled($product->barcode_ean)) {
            $parts[] = __('product_channels.identity.gtin', ['gtin' => $product->barcode_ean]);
        }

        return implode(' · ', $parts);
    }
}
