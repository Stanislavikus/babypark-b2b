<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

class WorkspaceAccess extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'Налаштування';

    protected static ?string $navigationLabel = 'Доступ';

    protected static ?string $title = 'Доступ';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.workspace-access';

    #[Url]
    public string $activeTab = 'members';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        $workspace = app(WorkspaceContext::class)->current();

        return app(WorkspaceAuthorization::class)->allows(
            $user,
            $workspace,
            WorkspacePermissions::MANAGE_WORKSPACE_ACCESS,
        );
    }

    public function switchTab(string $tab): void
    {
        if (! in_array($tab, ['members', 'roles'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    protected function resolveWorkspace(): Workspace
    {
        return app(WorkspaceContext::class)->current();
    }
}
