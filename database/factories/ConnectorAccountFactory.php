<?php

namespace Database\Factories;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Models\ConnectorAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorAccount>
 */
class ConnectorAccountFactory extends Factory
{
    use ResolvesConnectorFactoryGraph;

    protected $model = ConnectorAccount::class;

    public function definition(): array
    {
        $definition = $this->connectorDefinition();

        return [
            'workspace_id' => $this->workspaceId(),
            'connector_definition_id' => $definition->id,
            'name' => fake()->unique()->words(2, true),
            'auth_profile' => 'factory_auth_profile',
            'base_url' => 'https://example.com',
            'store_code' => 'default',
            'tenant_context' => null,
            'is_enabled' => true,
            'settings' => ['mode' => 'test'],
            'credentials' => ['token' => 'secret-value'],
            'connection_status' => ConnectorAccountConnectionStatus::Untested,
            'last_checked_at' => null,
            'last_successful_check_at' => null,
            'last_discovery_at' => null,
            'last_successful_discovery_at' => null,
            'last_error_cause' => null,
            'last_error_actionability' => null,
            'last_error_message_key' => null,
            'last_error_at' => null,
        ];
    }
}
