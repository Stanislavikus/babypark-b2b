<?php

namespace Database\Factories;

use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorDirection;
use App\Enums\ConnectorSchemaAcquisitionMode;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Enums\ConnectorSchemaVerificationStatus;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorSchemaSource;
use App\Models\Workspace;

trait ResolvesConnectorFactoryGraph
{
    protected function workspaceId(): string
    {
        $workspace = Workspace::query()->where('is_default', true)->first()
            ?? Workspace::query()->create([
                'name' => 'Factory Workspace',
                'is_default' => true,
            ]);

        return $workspace->id;
    }

    protected function connectorDefinition(): ConnectorDefinition
    {
        return ConnectorDefinition::query()->firstOrCreate(
            ['code' => 'factory_connector'],
            [
                'name' => 'Factory Connector',
                'direction' => ConnectorDirection::Both,
                'status' => ConnectorDefinitionStatus::Active,
            ],
        );
    }

    protected function schemaSourceForDefinition(ConnectorDefinition $definition): ConnectorSchemaSource
    {
        return ConnectorSchemaSource::query()->firstOrCreate(
            [
                'connector_definition_id' => $definition->id,
                'code' => 'factory_schema_source',
            ],
            [
                'label' => 'Factory schema source',
                'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
                'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
                'schema_scope' => ConnectorSchemaScope::Global,
                'reference_url' => 'https://example.com/schema',
                'is_primary' => true,
                'verification_status' => ConnectorSchemaVerificationStatus::Verified,
                'last_verified_at' => now(),
            ],
        );
    }
}
