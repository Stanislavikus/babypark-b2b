<?php

namespace App\Support\Platform;

use App\Enums\UserRole;
use App\Models\User;

final class PlatformAdminAuthorization
{
    public static function canManage(User $user): bool
    {
        return $user->is_active && in_array($user->role, [
            UserRole::Admin,
            UserRole::Programmer,
        ], true);
    }
}
