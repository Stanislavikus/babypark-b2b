<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
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
}
