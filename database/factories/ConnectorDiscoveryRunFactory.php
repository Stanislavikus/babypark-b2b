<?php

namespace Database\Factories;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorDiscoveryRunTrigger;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorDiscoveryRun>
 */
class ConnectorDiscoveryRunFactory extends Factory
{
    use ResolvesConnectorFactoryGraph;

    protected $model = ConnectorDiscoveryRun::class;

    public function definition(): array
    {
        $account = ConnectorAccount::factory()->create();
        $source = $this->schemaSourceForDefinition($account->connectorDefinition);

        return [
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => ConnectorDiscoveryRunTrigger::Manual,
            'initiated_by_user_id' => null,
            'status' => ConnectorDiscoveryRunStatus::Queued,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'fields_received' => null,
            'fields_normalized' => null,
            'added_count' => null,
            'changed_count' => null,
            'removed_count' => null,
            'unchanged_count' => null,
            'cause_category' => null,
            'actionability' => null,
            'error_code' => null,
            'http_status' => null,
            'user_message_key' => null,
            'technical_summary' => null,
            'vendor_request_id' => null,
            'snapshot_id' => null,
            'previous_snapshot_id' => null,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => ConnectorDiscoveryRunStatus::Succeeded,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'duration_ms' => 60000,
            'fields_received' => 10,
            'fields_normalized' => 10,
        ]);
    }
}
