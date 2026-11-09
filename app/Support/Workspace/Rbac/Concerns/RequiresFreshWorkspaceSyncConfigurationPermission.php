<?php

namespace App\Support\Workspace\Rbac\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\AdobeProductExportSetupAuthorizationService;
use App\Support\Workspace\WorkspaceContext;
use Illuminate\Support\Facades\Auth;

trait RequiresFreshWorkspaceSyncConfigurationPermission
{
    protected function resolveSyncSetupWorkspace(): Workspace
    {
        return app(WorkspaceContext::class)->current();
    }

    protected function authorizeFreshSyncConfigurationAccess(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        $workspace = $this->resolveSyncSetupWorkspace();

        if (! app(AdobeProductExportSetupAuthorizationService::class)->canAccess($user, $workspace)) {
            abort(403);
        }
    }

    public function bootRequiresFreshWorkspaceSyncConfigurationPermission(): void
    {
        $this->authorizeFreshSyncConfigurationAccess();
    }
}
