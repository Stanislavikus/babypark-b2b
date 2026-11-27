<?php

namespace App\Support\Workspace;

use App\Models\User;
use App\Models\Workspace;

final class WorkspaceMembership
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function belongs(User $user, Workspace $workspace): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->customer_id !== null) {
            return false;
        }

        // MVP: staff users belong to the default workspace until WorkspaceUser (GAP-004).
        return $workspace->is_default;
    }
}
