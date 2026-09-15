<?php

namespace App\Filament\Pages\Sync;

use App\Models\User;
use App\Services\Sync\SyncDataSetupLandingService;
use App\Support\Sync\AdobeProductExportSetup\SyncDataSetupTargetKind;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspaceSyncDataSetupLandingPermission;
use App\Support\Workspace\WorkspaceContext;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

class ListSyncDataSetup extends Page
{
    use RequiresFreshWorkspaceSyncDataSetupLandingPermission;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'sync-data-setup';

    protected string $view = 'filament.pages.sync.list-sync-data-setup';

    /** @var list<array<string, mixed>> */
    public array $targets = [];

    public static function canAccess(array $parameters = []): bool
    {
        $user = Auth::user();
        $workspace = app(WorkspaceContext::class)->current();

        return $user instanceof User
            && app(SyncDataSetupLandingService::class)->canAccessLanding($user, $workspace);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('sync_data_setup.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('sync_data_setup.navigation.label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('sync_data_setup.page.title');
    }

    public function mount(): void
    {
        $this->refreshTargets();
    }

    private function refreshTargets(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $this->resolveSyncDataSetupLandingWorkspace();

        $summaries = app(SyncDataSetupLandingService::class)
            ->listLandingTargets($user, $workspace);

        $this->targets = array_map(
            fn ($summary): array => [
                'account_id' => $summary->accountId,
                'platform_name' => $summary->platformName,
                'account_name' => $summary->accountName,
                'setup_usable' => $summary->setupUsable,
                'target_label' => $this->presentTargetLabel($summary->targetKind),
                'setup_action_visible' => $summary->setupActionVisible,
                'preview_action_visible' => $summary->previewActionVisible,
                'live_action_visible' => $summary->liveActionVisible,
                'setup_url' => $summary->setupUrl,
                'preview_url' => $summary->previewUrl,
                'live_url' => $summary->liveUrl,
            ],
            $summaries,
        );
    }

    private function presentTargetLabel(SyncDataSetupTargetKind $targetKind): string
    {
        return match ($targetKind) {
            SyncDataSetupTargetKind::AdobeProductsExport => __('sync_data_setup.targets.adobe_products_export'),
        };
    }
}
