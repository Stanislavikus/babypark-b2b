<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ConnectorAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspaceMembership;
use App\Support\Workspace\WorkspacePermissions;

class ConnectorAccountPolicy
{
    public function __construct(
        private readonly WorkspaceMembership $workspaceMembership,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allowsReadAbilityForWorkspace(
            $user,
            $this->workspaceContext->current(),
        );
    }

    public function view(User $user, ConnectorAccount $connectorAccount): bool
    {
        return $this->allowsReadAbility($user, $connectorAccount);
    }

    public function runConnectionCheck(User $user, ConnectorAccount $connectorAccount): bool
    {
        return $this->allowsManagementAbility($user, $connectorAccount);
    }

    public function runDiscovery(User $user, ConnectorAccount $connectorAccount): bool
    {
        if (! $this->workspaceMembership->belongs($user, $connectorAccount->workspace)) {
            return false;
        }

        if (! $connectorAccount->is_enabled) {
            return false;
        }

        if (in_array($user->role, [UserRole::Admin, UserRole::Director], true)) {
            return true;
        }

        if ($user->role === UserRole::Merchandiser) {
            return true;
        }

        return $user->can(WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);
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

    private function allowsReadAbility(User $user, ConnectorAccount $connectorAccount): bool
    {
        if (! $this->workspaceMembership->belongs($user, $connectorAccount->workspace)) {
            return false;
        }

        return $this->allowsReadAbilityForWorkspace($user, $connectorAccount->workspace);
    }

    private function allowsReadAbilityForWorkspace(User $user, Workspace $workspace): bool
    {
        if (! $this->workspaceMembership->belongs($user, $workspace)) {
            return false;
        }

        if ($user->role === UserRole::Merchandiser) {
            return true;
        }

        return $this->allowsManagementAbilityForWorkspace($user, $workspace);
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
