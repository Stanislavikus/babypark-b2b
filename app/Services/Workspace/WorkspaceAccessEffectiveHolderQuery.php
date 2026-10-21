<?php

namespace App\Services\Workspace;

use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Support\Facades\DB;

final class WorkspaceAccessEffectiveHolderQuery
{
    public function hasEffectiveHolder(string $workspaceId): bool
    {
        return $this->countEffectiveHolders($workspaceId) > 0;
    }

    public function countEffectiveHolders(string $workspaceId): int
    {
        return (int) DB::table('workspace_users')
            ->join('users', 'users.id', '=', 'workspace_users.user_id')
            ->where('workspace_users.workspace_id', $workspaceId)
            ->where('workspace_users.is_active', true)
            ->where('users.is_active', true)
            ->whereExists(function ($query) use ($workspaceId): void {
                $query->select(DB::raw('1'))
                    ->from('workspace_user_roles')
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
                    ->whereColumn('workspace_user_roles.workspace_user_id', 'workspace_users.id')
                    ->where('workspace_user_roles.workspace_id', $workspaceId)
                    ->where('workspace_role_permissions.workspace_id', $workspaceId)
                    ->where('workspace_permissions.code', WorkspacePermissions::MANAGE_WORKSPACE_ACCESS)
                    ->whereIn('workspace_permissions.code', WorkspacePermissions::catalogue());
            })
            ->distinct()
            ->count('workspace_users.id');
    }
}
