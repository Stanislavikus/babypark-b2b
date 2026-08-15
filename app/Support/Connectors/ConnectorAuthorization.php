<?php

namespace App\Support\Connectors;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspacePermissions;

/**
 * Connector-specific authorization matrix over authoritative Workspace RBAC.
 * Not a second authority source — delegates exclusively to WorkspaceAuthorization.
 */
final class ConnectorAuthorization
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
    ) {}

    public function canSafeRead(User $user, Workspace $workspace): bool
    {
        return $this->hasAnyPermission($user, $workspace, [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::RUN_CONNECTOR_DISCOVERY,
            WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS,
        ]);
    }

    public function canDiscoveryControl(User $user, Workspace $workspace): bool
    {
        return $this->hasAnyPermission($user, $workspace, [
            WorkspacePermissions::RUN_CONNECTOR_DISCOVERY,
            WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS,
        ]);
    }

    public function canManage(User $user, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows(
            $user,
            $workspace,
            WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS,
        );
    }

    public function canReadSyncMappings(User $user, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows($user, $workspace, WorkspacePermissions::VIEW_SYNC_MAPPINGS)
            || $this->workspaceAuthorization->allows($user, $workspace, WorkspacePermissions::MANAGE_SYNC_MAPPINGS);
    }

    public function canLayerBExternalFieldReference(User $user, Workspace $workspace): bool
    {
        return $this->canSafeRead($user, $workspace)
            || $this->canReadSyncMappings($user, $workspace);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function hasAnyPermission(User $user, Workspace $workspace, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->workspaceAuthorization->allows($user, $workspace, $permission)) {
                return true;
            }
        }

        return false;
    }
}
