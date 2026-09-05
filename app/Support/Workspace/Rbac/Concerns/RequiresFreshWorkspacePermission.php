<?php

namespace App\Support\Workspace\Rbac\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Support\Facades\Auth;

trait RequiresFreshWorkspacePermission
{
    protected function resolveAccessWorkspace(): Workspace
    {
        return app(WorkspaceContext::class)->current();
    }

    protected function authorizeFreshWorkspaceAccess(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        $workspace = $this->resolveAccessWorkspace();

        if (! app(WorkspaceAuthorization::class)->allows($user, $workspace, WorkspacePermissions::MANAGE_WORKSPACE_ACCESS)) {
            abort(403);
        }
    }

    public function bootRequiresFreshWorkspacePermission(): void
    {
        $this->authorizeFreshWorkspaceAccess();
    }
}
