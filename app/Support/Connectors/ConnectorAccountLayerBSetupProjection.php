<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;

/**
 * Positive allow-list projection for Layer-B sync data setup surfaces.
 * Fetches only merchant-safe connector account identity and usability state.
 */
final readonly class ConnectorAccountLayerBSetupProjection
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
        public bool $isEnabled,
        public ConnectorAccountConnectionStatus $connectionStatus,
        public bool $setupUsable,
    ) {}

    public static function fromAccount(ConnectorAccount $account): self
    {
        $definition = $account->relationLoaded('connectorDefinition')
            ? $account->connectorDefinition
            : ConnectorDefinition::query()->find($account->connector_definition_id);

        $platformName = $definition?->name ?? '';

        return new self(
            id: (string) $account->getKey(),
            workspaceId: $account->workspace_id,
            connectorDefinitionId: $account->connector_definition_id,
            platformName: $platformName,
            accountName: $account->name,
            isEnabled: (bool) $account->is_enabled,
            connectionStatus: $account->connection_status,
            setupUsable: self::isSetupUsable($account),
        );
    }

    public static function isSetupUsable(ConnectorAccount $account): bool
    {
        return (bool) $account->is_enabled;
    }

    /**
     * Defense in depth when a hydrated model is returned alongside the projection.
     */
    public static function sanitizeLoadedAccount(ConnectorAccount $account): ConnectorAccount
    {
        return $account->makeHidden(ConnectorAccountCapabilityPresentation::hiddenAttributes());
    }
}
