<?php

namespace Database\Factories;

use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConnectorSchemaSnapshot>
 */
class ConnectorSchemaSnapshotFactory extends Factory
{
    protected $model = ConnectorSchemaSnapshot::class;

    public function definition(): array
    {
        $run = ConnectorDiscoveryRun::factory()->succeeded()->create();

        return [
            'workspace_id' => $run->workspace_id,
            'connector_account_id' => $run->connector_account_id,
            'connector_schema_source_id' => $run->connector_schema_source_id,
            'discovery_run_id' => $run->id,
            'previous_snapshot_id' => null,
            'schema_version' => '1.0',
            'field_count' => 1,
            'canonical_hash' => hash('sha256', Str::uuid()->toString()),
            'captured_at' => now(),
        ];
    }
}
