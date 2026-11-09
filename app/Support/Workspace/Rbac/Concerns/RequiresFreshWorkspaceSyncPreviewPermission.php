<?php

namespace App\Support\Workspace\Rbac\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\AdobeProductsExportPreviewAuthorizationService;
use App\Support\Workspace\WorkspaceContext;
use Illuminate\Support\Facades\Auth;

trait RequiresFreshWorkspaceSyncPreviewPermission
{
    protected function resolveSyncPreviewWorkspace(): Workspace
    {
        return app(WorkspaceContext::class)->current();
    }

    protected function authorizeFreshSyncPreviewAccess(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        $workspace = $this->resolveSyncPreviewWorkspace();

        if (! app(AdobeProductsExportPreviewAuthorizationService::class)->canAccess($user, $workspace)) {
            abort(403);
        }
    }

    public function bootRequiresFreshWorkspaceSyncPreviewPermission(): void
    {
        $this->authorizeFreshSyncPreviewAccess();
    }
}
