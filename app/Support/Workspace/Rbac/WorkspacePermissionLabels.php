<?php

namespace App\Support\Workspace\Rbac;

use App\Support\Workspace\WorkspacePermissions;

final class WorkspacePermissionLabels
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (WorkspacePermissions::catalogue() as $code) {
            $options[$code] = __("workspace_access.permissions.{$code}");
        }

        return $options;
    }

    public static function label(string $code): string
    {
        return __("workspace_access.permissions.{$code}");
    }
}
