<?php

namespace App\Support\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;

final class WorkspaceTaxSettingsAuthorization
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function canManage(User $user, ?Workspace $workspace = null): bool
    {
        $workspace ??= $this->workspaceContext->current();

        return $this->workspaceAuthorization->allows(
            $user,
            $workspace,
            WorkspacePermissions::MANAGE_TAX_SETTINGS,
        );
    }
}
