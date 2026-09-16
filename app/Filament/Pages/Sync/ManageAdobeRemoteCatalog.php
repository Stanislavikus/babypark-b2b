<?php

namespace App\Filament\Pages\Sync;

use App\Models\ConnectorAccount;
use App\Models\RemoteCatalogSnapshot;
use App\Models\RemoteCatalogSnapshotItem;
use App\Models\User;
use App\Services\Connectors\AdobeRemoteCatalogProjectionService;
use App\Services\Sync\SyncDataSetupLandingService;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspaceSyncDataSetupLandingPermission;
use App\Support\Workspace\WorkspaceContext;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
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
            ->paginated([20, 50, 100])
            ->defaultPaginationPageOption(20)
            ->defaultSort('name');
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
    }
}
