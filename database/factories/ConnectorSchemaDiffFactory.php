<?php

namespace Database\Factories;

use App\Models\ConnectorSchemaDiff;
use App\Models\ConnectorSchemaSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorSchemaDiff>
 */
class ConnectorSchemaDiffFactory extends Factory
{
    protected $model = ConnectorSchemaDiff::class;

    public function definition(): array
    {
        $toSnapshot = ConnectorSchemaSnapshot::factory()->create();

        return [
            'workspace_id' => $toSnapshot->workspace_id,
            'connector_account_id' => $toSnapshot->connector_account_id,
            'connector_schema_source_id' => $toSnapshot->connector_schema_source_id,
            'from_snapshot_id' => null,
            'to_snapshot_id' => $toSnapshot->id,
            'is_first_snapshot' => true,
            'added_count' => 1,
            'changed_count' => 0,
            'removed_count' => 0,
            'unchanged_count' => 0,
        ];
    }

    public function nonBaseline(): static
    {
        return $this->state(function () {
            $fromSnapshot = ConnectorSchemaSnapshot::factory()->create();
            $toSnapshot = ConnectorSchemaSnapshot::factory()->create([
                'workspace_id' => $fromSnapshot->workspace_id,
                'connector_account_id' => $fromSnapshot->connector_account_id,
                'connector_schema_source_id' => $fromSnapshot->connector_schema_source_id,
            ]);

            return [
                'workspace_id' => $toSnapshot->workspace_id,
                'connector_account_id' => $toSnapshot->connector_account_id,
                'connector_schema_source_id' => $toSnapshot->connector_schema_source_id,
                'from_snapshot_id' => $fromSnapshot->id,
                'to_snapshot_id' => $toSnapshot->id,
                'is_first_snapshot' => false,
                'added_count' => 0,
                'changed_count' => 1,
                'removed_count' => 0,
                'unchanged_count' => 0,
            ];
        });
    }
}
