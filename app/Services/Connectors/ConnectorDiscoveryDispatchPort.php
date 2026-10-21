<?php

namespace App\Services\Connectors;

use App\Models\User;
use App\Support\Connectors\ConnectorDiscoveryDispatchDecision;

interface ConnectorDiscoveryDispatchPort
{
    public function executeManual(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
    ): ConnectorDiscoveryDispatchDecision;
}
