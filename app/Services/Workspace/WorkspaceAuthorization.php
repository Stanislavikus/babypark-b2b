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
        $codes = DB::table('users')
            ->join('workspace_users', 'workspace_users.user_id', '=', 'users.id')
            ->join('workspace_user_roles', 'workspace_user_roles.workspace_user_id', '=', 'workspace_users.id')
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
            ->where('users.id', $user->id)
            ->where('workspace_users.workspace_id', $workspace->id)
            ->where('workspace_user_roles.workspace_id', $workspace->id)
            ->where('workspace_role_permissions.workspace_id', $workspace->id)
            ->where('users.is_active', true)
            ->where('workspace_users.is_active', true)
            ->whereIn('workspace_permissions.code', WorkspacePermissions::catalogue())
            ->distinct()
            ->orderBy('workspace_permissions.code')
            ->pluck('workspace_permissions.code')
            ->all();

        return array_values($codes);
    }

    public function activeMembership(User $user, Workspace $workspace): ?WorkspaceUser
    {
        $membershipId = DB::table('users')
            ->join('workspace_users', 'workspace_users.user_id', '=', 'users.id')
            ->where('users.id', $user->id)
            ->where('workspace_users.workspace_id', $workspace->id)
            ->where('users.is_active', true)
            ->where('workspace_users.is_active', true)
            ->value('workspace_users.id');

        if ($membershipId === null) {
            return null;
        }

        return WorkspaceUser::query()->find($membershipId);
    }
}
