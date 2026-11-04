<?php

namespace App\Support\Workspace\Rbac\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\SyncDataSetupLandingService;
use App\Support\Workspace\WorkspaceContext;
use Illuminate\Support\Facades\Auth;

trait RequiresFreshWorkspaceSyncDataSetupLandingPermission
{
    protected function resolveSyncDataSetupLandingWorkspace(): Workspace
    {
        return app(WorkspaceContext::class)->current();
    }

    protected function authorizeFreshSyncDataSetupLandingAccess(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        $workspace = $this->resolveSyncDataSetupLandingWorkspace();

        if (! app(SyncDataSetupLandingService::class)->canAccessLanding($user, $workspace)) {
            abort(403);
        }
    }

    public function bootRequiresFreshWorkspaceSyncDataSetupLandingPermission(): void
    {
        $this->authorizeFreshSyncDataSetupLandingAccess();
    }
}
