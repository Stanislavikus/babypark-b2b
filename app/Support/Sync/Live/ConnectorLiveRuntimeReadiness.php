<?php

namespace App\Support\Sync\Live;

interface ConnectorLiveRuntimeReadiness
{
    public function isReady(string $workspaceId, string $connectorAccountId): bool;
}
