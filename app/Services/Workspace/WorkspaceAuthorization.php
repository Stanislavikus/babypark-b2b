<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Support\Facades\DB;

final class WorkspaceAuthorization
{
    public function allows(User $user, Workspace $workspace, string $permission): bool
    {
        if (! in_array($permission, WorkspacePermissions::catalogue(), true)) {
            return false;
        }

        return in_array($permission, $this->effectivePermissions($user, $workspace), true);
    }

    /**
     * @return list<string>
     */
    public function effectivePermissions(User $user, Workspace $workspace): array
    {
        $membership = $this->activeMembership($user, $workspace);

        if ($membership === null) {
            return [];
        }

        $codes = DB::table('workspace_user_roles')
            ->join(
                'workspace_role_permissions',
                'workspace_role_permissions.workspace_role_id',
                '=',
                'workspace_user_roles.workspace_role_id',
            )
            ->join(
                'workspace_permissions',
                'workspace_permissions.id',
                '=',
                'workspace_role_permissions.workspace_permission_id',
            )
            ->where('workspace_user_roles.workspace_user_id', $membership->id)
            ->where('workspace_user_roles.workspace_id', $workspace->id)
            ->where('workspace_role_permissions.workspace_id', $workspace->id)
            ->whereIn('workspace_permissions.code', WorkspacePermissions::catalogue())
            ->distinct()
            ->orderBy('workspace_permissions.code')
            ->pluck('workspace_permissions.code')
            ->all();

        return array_values($codes);
    }

    public function activeMembership(User $user, Workspace $workspace): ?WorkspaceUser
    {
        if (! $user->is_active) {
            return null;
        }

        return WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();
    }
}
