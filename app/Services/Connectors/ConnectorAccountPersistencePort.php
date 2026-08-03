<?php

namespace App\Services\Connectors;

use App\Models\User;
use App\Models\Workspace;

interface ConnectorAccountPersistencePort
{
    public function create(
        User $actor,
        Workspace $workspace,
        CreateConnectorAccountInput $input,
    ): ConnectorAccountSettingsResult;

    public function update(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
        UpdateConnectorAccountInput $input,
    ): ConnectorAccountSettingsResult;
}
