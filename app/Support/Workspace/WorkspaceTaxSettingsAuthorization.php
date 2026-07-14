<?php

namespace App\Support\Workspace;

use App\Enums\UserRole;
use App\Models\User;

final class WorkspaceTaxSettingsAuthorization
{
    public static function canManage(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return in_array($user->role, [
            UserRole::Admin,
            UserRole::Director,
        ], true) || $user->can(WorkspacePermissions::MANAGE_TAX_SETTINGS);
    }
}
