<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ConnectorAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\WorkspaceMembership;
use App\Support\Workspace\WorkspacePermissions;

class ConnectorAccountPolicy
{
    public function __construct(
        private readonly WorkspaceMembership $workspaceMembership,
    ) {}

    public function view(User $user, ConnectorAccount $connectorAccount): bool
    {
        return $this->allowsManagementAbility($user, $connectorAccount);
    }

    public function runConnectionCheck(User $user, ConnectorAccount $connectorAccount): bool
    {
        return $this->allowsManagementAbility($user, $connectorAccount);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $this->allowsManagementAbilityForWorkspace($user, $workspace);
    }

    public function updateSettings(User $user, ConnectorAccount $connectorAccount): bool
    {
        return $this->allowsManagementAbility($user, $connectorAccount);
    }

    public function replaceCredentials(User $user, ConnectorAccount $connectorAccount): bool
    {
        return $this->allowsManagementAbility($user, $connectorAccount);
    }

    public function removeCredentials(User $user, ConnectorAccount $connectorAccount): bool
    {
        return $this->allowsManagementAbility($user, $connectorAccount);
    }

    private function allowsManagementAbility(User $user, ConnectorAccount $connectorAccount): bool
    {
        if (! $this->workspaceMembership->belongs($user, $connectorAccount->workspace)) {
            return false;
        }

        return $this->allowsManagementAbilityForWorkspace($user, $connectorAccount->workspace);
    }

    private function allowsManagementAbilityForWorkspace(User $user, Workspace $workspace): bool
    {
        if (! $this->workspaceMembership->belongs($user, $workspace)) {
            return false;
        }

        if ($user->role === UserRole::Merchandiser) {
            return false;
        }

        if (in_array($user->role, [UserRole::Admin, UserRole::Director], true)) {
            return true;
        }

        return $user->can(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);
    }
}
