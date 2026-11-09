<?php

namespace App\Support\Workspace\Rbac\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Connectors\ConnectorAuthorization;
use App\Support\Workspace\WorkspaceContext;
use Illuminate\Support\Facades\Auth;

trait RequiresFreshWorkspaceMappingPermission
{
    protected function resolveMappingWorkspace(): Workspace
    {
        return app(WorkspaceContext::class)->current();
    }

    protected function authorizeFreshMappingAccess(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        $workspace = $this->resolveMappingWorkspace();

        if (! app(ConnectorAuthorization::class)->canReadSyncMappings($user, $workspace)) {
            abort(403);
        }
    }

    public function bootRequiresFreshWorkspaceMappingPermission(): void
    {
        $this->authorizeFreshMappingAccess();
    }
}
