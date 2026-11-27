<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;

/**
 * Internal positive allow-list projection for Layer-B setup eligibility checks.
 * Includes auth_profile for connector/profile resolution only — never merchant UI.
 */
final readonly class ConnectorAccountLayerBSetupEligibilityProjection
{
    /**
     * @return list<string>
     */
    public static function selectColumns(): array
    {
        return [
            'id',
            'workspace_id',
            'connector_definition_id',
            'name',
            'auth_profile',
            'is_enabled',
            'connection_status',
        ];
    }

    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $connectorDefinitionId,
        public string $platformName,
        public string $accountName,
        public string $authProfile,
        public bool $isEnabled,
        public ConnectorAccountConnectionStatus $connectionStatus,
    ) {}

    public static function fromAccount(ConnectorAccount $account): self
    {
        $definition = $account->relationLoaded('connectorDefinition')
            ? $account->connectorDefinition
            : ConnectorDefinition::query()->find($account->connector_definition_id);

        return new self(
            id: (string) $account->getKey(),
            workspaceId: $account->workspace_id,
            connectorDefinitionId: $account->connector_definition_id,
            platformName: $definition?->name ?? '',
            accountName: $account->name,
            authProfile: (string) $account->auth_profile,
            isEnabled: (bool) $account->is_enabled,
            connectionStatus: $account->connection_status,
        );
    }

    public function isSetupUsable(): bool
    {
        return $this->isEnabled;
    }
}
