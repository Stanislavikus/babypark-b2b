<?php

namespace Tests\Feature\Connectors;

use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorSchemaDiscoveryV2MigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function migration_exposes_versioned_snapshot_and_normalization_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('connector_discovery_runs', [
            'fields_identified',
            'fields_unclassified',
        ]));
        $this->assertTrue(Schema::hasColumn('connector_schema_snapshots', 'canonical_hash_version'));
        $this->assertTrue(Schema::hasColumns('connector_schema_snapshot_fields', [
            'normalization_status',
            'normalization_failure_reason',
        ]));
    }

    #[Test]
    public function historical_factory_rows_remain_explicit_v1_normalized_rows(): void
    {
        $field = ConnectorSchemaSnapshotField::factory()->create();
        $field->refresh();
        $snapshot = $field->snapshot()->firstOrFail();

        $this->assertSame('v1', $snapshot->canonical_hash_version);
        $this->assertSame('normalized', $field->normalization_status->value);
        $this->assertNull($field->normalization_failure_reason);
        $this->assertNotNull($field->normalized_data_type);
    }

    #[Test]
    public function database_accepts_unclassified_field_only_with_null_normalized_type(): void
    {
        $snapshot = ConnectorSchemaSnapshot::factory()->create();

        DB::table('connector_schema_snapshot_fields')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $snapshot->workspace_id,
            'snapshot_id' => $snapshot->id,
            'external_field_key' => 'vendor_mystery',
            'external_label' => 'Vendor mystery',
            'normalization_status' => 'unclassified',
            'normalization_failure_reason' => 'unmapped_value',
            'normalized_data_type' => null,
            'is_required' => null,
            'is_multi_value' => null,
            'is_localizable' => null,
            'external_scope' => null,
            'normalized_payload' => '{}',
            'canonical_hash' => hash('sha256', 'vendor_mystery'),
            'sort_order' => null,
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('connector_schema_snapshot_fields', [
            'snapshot_id' => $snapshot->id,
            'external_field_key' => 'vendor_mystery',
            'normalization_status' => 'unclassified',
            'normalized_data_type' => null,
        ]);
    }

    #[Test]
    public function database_rejects_normalized_field_without_normalized_type(): void
    {
        $this->expectException(QueryException::class);

        $this->insertInvalidState('normalized', null, null);
    }

    #[Test]
    public function database_rejects_unclassified_field_with_normalized_type(): void
    {
        $this->expectException(QueryException::class);

        $this->insertInvalidState('unclassified', 'text', 'unmapped_value');
    }

    #[Test]
    public function database_rejects_normalized_field_with_failure_reason(): void
    {
        $this->expectException(QueryException::class);

        $this->insertInvalidState('normalized', 'text', 'unmapped_value');
    }

    #[Test]
    public function downgrade_fails_closed_after_v2_runtime_data_exists(): void
    {
        ConnectorSchemaSnapshot::factory()->create([
            'canonical_hash_version' => 'v2',
        ]);

        $migration = require database_path('migrations/2026_09_12_120000_connector_schema_discovery_v2.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot downgrade connector schema discovery v2');

        $migration->down();
    }

    private function insertInvalidState(string $status, ?string $type, ?string $reason): void
    {
        $snapshot = ConnectorSchemaSnapshot::factory()->create();

        DB::table('connector_schema_snapshot_fields')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $snapshot->workspace_id,
            'snapshot_id' => $snapshot->id,
            'external_field_key' => 'invalid_'.Str::lower(Str::random(8)),
            'external_label' => null,
            'normalization_status' => $status,
            'normalization_failure_reason' => $reason,
            'normalized_data_type' => $type,
            'is_required' => null,
            'is_multi_value' => null,
            'is_localizable' => null,
            'external_scope' => null,
            'normalized_payload' => '{}',
            'canonical_hash' => hash('sha256', Str::random()),
            'sort_order' => null,
            'created_at' => now(),
        ]);
    }
}
