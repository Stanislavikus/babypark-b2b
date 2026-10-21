<?php

namespace App\Support\Workspace\Rbac;

use App\Models\WorkspaceRole;

final class WorkspaceRoleLabelResolver
{
    public static function resolve(string $workspaceId, string $roleId): ?string
    {
        return WorkspaceRole::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $roleId)
            ->value('name');
    }
}
