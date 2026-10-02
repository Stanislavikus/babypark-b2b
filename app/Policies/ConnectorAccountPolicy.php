<?php

namespace App\Policies;

use App\Models\ConnectorAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Connectors\ConnectorAuthorization;
use App\Support\Workspace\WorkspaceContext;

class ConnectorAccountPolicy
{
    public function __construct(
        private readonly ConnectorAuthorization $connectorAuthorization,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->connectorAuthorization->canSafeRead(
            $user,
            $this->workspaceContext->current(),
        );
    }

    public function view(User $user, ConnectorAccount $connectorAccount): bool
    {
        return $this->connectorAuthorization->canSafeRead($user, $connectorAccount->workspace);
    }

    public function runConnectionCheck(User $user, ConnectorAccount $connectorAccount): bool
    {
        return $this->connectorAuthorization->canManage($user, $connectorAccount->workspace);
    }

    public function viewRunDiscovery(User $user, ConnectorAccount $connectorAccount): bool
    {
        return $this->connectorAuthorization->canDiscoveryControl($user, $connectorAccount->workspace);
    }

    public function runDiscovery(User $user, ConnectorAccount $connectorAccount): bool
    {
        if (! $this->connectorAuthorization->canDiscoveryControl($user, $connectorAccount->workspace)) {
            return false;
        }

        return $connectorAccount->is_enabled;
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $this->connectorAuthorization->canManage($user, $workspace);
    }

    public function updateSettings(User $user, ConnectorAccount $connectorAccount): bool
    {
        return $this->connectorAuthorization->canManage($user, $connectorAccount->workspace);
    }

    public function replaceCredentials(User $user, ConnectorAccount $connectorAccount): bool
    {
        return $this->connectorAuthorization->canManage($user, $connectorAccount->workspace);
    }

    public function removeCredentials(User $user, ConnectorAccount $connectorAccount): bool
    {
        return $this->connectorAuthorization->canManage($user, $connectorAccount->workspace);
    }
}
