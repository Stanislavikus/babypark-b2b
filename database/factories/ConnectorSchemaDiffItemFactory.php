<?php

namespace Database\Factories;

use App\Enums\ConnectorSchemaDiffItemChangeType;
use App\Models\ConnectorSchemaDiff;
use App\Models\ConnectorSchemaDiffItem;
use App\Models\ConnectorSchemaSnapshotField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorSchemaDiffItem>
 */
class ConnectorSchemaDiffItemFactory extends Factory
{
    protected $model = ConnectorSchemaDiffItem::class;

    public function definition(): array
    {
        $diff = ConnectorSchemaDiff::factory()->create();
        $afterField = ConnectorSchemaSnapshotField::factory()->create([
            'workspace_id' => $diff->workspace_id,
            'snapshot_id' => $diff->to_snapshot_id,
        ]);

        return [
            'workspace_id' => $diff->workspace_id,
            'connector_schema_diff_id' => $diff->id,
            'change_type' => ConnectorSchemaDiffItemChangeType::Added,
            'external_field_key' => $afterField->external_field_key,
            'before_snapshot_field_id' => null,
            'after_snapshot_field_id' => $afterField->id,
            'changed_paths' => null,
        ];
    }

    public function changed(): static
    {
        return $this->state(function () {
            $diff = ConnectorSchemaDiff::factory()->nonBaseline()->create();
            $beforeField = ConnectorSchemaSnapshotField::factory()->create([
                'workspace_id' => $diff->workspace_id,
                'snapshot_id' => $diff->from_snapshot_id,
                'external_field_key' => 'sku',
            ]);
            $afterField = ConnectorSchemaSnapshotField::factory()->create([
                'workspace_id' => $diff->workspace_id,
                'snapshot_id' => $diff->to_snapshot_id,
                'external_field_key' => 'sku',
            ]);

            return [
                'workspace_id' => $diff->workspace_id,
                'connector_schema_diff_id' => $diff->id,
                'change_type' => ConnectorSchemaDiffItemChangeType::Changed,
                'external_field_key' => 'sku',
                'before_snapshot_field_id' => $beforeField->id,
                'after_snapshot_field_id' => $afterField->id,
                'changed_paths' => ['label'],
            ];
        });
    }
}
