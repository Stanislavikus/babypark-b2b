<?php

namespace Database\Factories;

use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConnectorSchemaSnapshotField>
 */
class ConnectorSchemaSnapshotFieldFactory extends Factory
{
    protected $model = ConnectorSchemaSnapshotField::class;

    public function definition(): array
    {
        $snapshot = ConnectorSchemaSnapshot::factory()->create();

        return [
            'workspace_id' => $snapshot->workspace_id,
            'snapshot_id' => $snapshot->id,
            'external_field_key' => fake()->unique()->slug(2),
            'external_label' => fake()->words(2, true),
            'normalized_data_type' => 'string',
            'is_required' => false,
            'is_multi_value' => false,
            'is_localizable' => false,
            'external_scope' => 'global',
            'normalized_payload' => ['source' => 'factory'],
            'canonical_hash' => hash('sha256', Str::uuid()->toString()),
            'sort_order' => 1,
        ];
    }
}
