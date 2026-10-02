<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Support\Connectors\ConnectorAccountLayerBSetupEligibilityProjection;
use App\Support\Connectors\ConnectorAccountLayerBSetupProjection;

final class ConnectorAccountLayerBSetupProjectionQuery
{
    public function resolve(string $workspaceId, string $connectorAccountId): ?ConnectorAccountLayerBSetupProjection
    {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->select(ConnectorAccountLayerBSetupProjection::selectColumns())
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectorAccountId)
            ->with('connectorDefinition:id,name,code')
            ->first();

        if ($account === null) {
            return null;
        }

        return ConnectorAccountLayerBSetupProjection::fromAccount($account);
    }

    public function resolveEligibility(
        string $workspaceId,
        string $connectorAccountId,
    ): ?ConnectorAccountLayerBSetupEligibilityProjection {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->select(ConnectorAccountLayerBSetupEligibilityProjection::selectColumns())
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectorAccountId)
            ->with('connectorDefinition:id,name,code')
            ->first();

        if ($account === null) {
            return null;
        }

        return ConnectorAccountLayerBSetupEligibilityProjection::fromAccount($account);
    }

    /**
     * @return list<ConnectorAccountLayerBSetupProjection>
     */
    public function listForWorkspace(string $workspaceId): array
    {
        return ConnectorAccount::withoutWorkspaceScope()
            ->select(ConnectorAccountLayerBSetupProjection::selectColumns())
            ->where('workspace_id', $workspaceId)
            ->with('connectorDefinition:id,name,code')
            ->orderBy('name')
            ->get()
            ->map(fn (ConnectorAccount $account): ConnectorAccountLayerBSetupProjection => ConnectorAccountLayerBSetupProjection::fromAccount($account))
            ->all();
    }

    /**
     * @return list<ConnectorAccountLayerBSetupEligibilityProjection>
     */
    public function listEligibilityForWorkspace(string $workspaceId): array
    {
        return ConnectorAccount::withoutWorkspaceScope()
            ->select(ConnectorAccountLayerBSetupEligibilityProjection::selectColumns())
            ->where('workspace_id', $workspaceId)
            ->with('connectorDefinition:id,name,code')
            ->orderBy('name')
            ->get()
            ->map(
                fn (ConnectorAccount $account): ConnectorAccountLayerBSetupEligibilityProjection => ConnectorAccountLayerBSetupEligibilityProjection::fromAccount($account),
            )
            ->all();
    }
}
