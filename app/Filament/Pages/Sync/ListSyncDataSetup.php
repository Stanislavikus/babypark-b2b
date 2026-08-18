<?php

namespace App\Filament\Pages\Sync;

use App\Models\User;
use App\Services\Sync\AdobeProductExportSetupAuthorizationService;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspaceSyncConfigurationPermission;
use App\Support\Workspace\WorkspaceContext;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

class ListSyncDataSetup extends Page
{
    use RequiresFreshWorkspaceSyncConfigurationPermission;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'sync-data-setup';

    protected string $view = 'filament.pages.sync.list-sync-data-setup';

    /** @var list<array{account_id: string, platform_name: string, account_name: string, setup_usable: bool, target_label: string, setup_url: string}> */
    public array $targets = [];

    public static function canAccess(array $parameters = []): bool
    {
        $user = Auth::user();
        $workspace = app(WorkspaceContext::class)->current();

        return $user instanceof User
            && app(AdobeProductExportSetupAuthorizationService::class)->canAccess($user, $workspace);
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

        $workspace = $this->resolveSyncSetupWorkspace();

        $summaries = app(AdobeProductExportSetupAuthorizationService::class)
            ->listEligibleSetupTargets($user, $workspace);

        $this->targets = array_map(
            static fn ($summary): array => [
                'account_id' => $summary->accountId,
                'platform_name' => $summary->platformName,
                'account_name' => $summary->accountName,
                'setup_usable' => $summary->setupUsable,
                'target_label' => $summary->targetLabel,
                'setup_url' => $summary->setupUrl,
            ],
            $summaries,
        );
    }
}
