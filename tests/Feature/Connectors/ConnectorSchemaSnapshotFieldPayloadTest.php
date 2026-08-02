<?php

namespace Tests\Feature\Connectors;

use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorSchemaSnapshotFieldPayloadTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function empty_object_payload_round_trips_through_database(): void
    {
        $snapshot = $this->createSnapshot();
        $field = $this->persistField($snapshot, (object) []);

        $field->refresh();

        $this->assertInstanceOf(\stdClass::class, $field->normalized_payload);
        $this->assertSame('{}', json_encode($field->normalized_payload, JSON_THROW_ON_ERROR));
        $this->assertFalse(property_exists($field->normalized_payload, 'options'));
    }

    #[Test]
    public function empty_options_array_payload_round_trips_through_database(): void
    {
        $payload = json_decode('{"options":[]}', associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);
        $snapshot = $this->createSnapshot();
        $field = $this->persistField($snapshot, $payload);

        $field->refresh();

        $this->assertInstanceOf(\stdClass::class, $field->normalized_payload);
        $this->assertTrue(property_exists($field->normalized_payload, 'options'));
        $this->assertIsArray($field->normalized_payload->options);
        $this->assertSame([], $field->normalized_payload->options);
        $this->assertSame('{"options":[]}', json_encode($field->normalized_payload, JSON_THROW_ON_ERROR));
    }

    private function createSnapshot(): ConnectorSchemaSnapshot
    {
        $account = $this->createConnectorAccount();

        return ConnectorSchemaSnapshot::factory()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
        ]);
    }

    private function persistField(
        ConnectorSchemaSnapshot $snapshot,
        object $normalizedPayload,
    ): ConnectorSchemaSnapshotField {
        return ConnectorSchemaSnapshotField::withoutWorkspaceScope()->create([
            'workspace_id' => $snapshot->workspace_id,
            'snapshot_id' => $snapshot->id,
            'external_field_key' => 'color',
            'external_label' => 'Color',
            'normalized_data_type' => 'string',
            'is_required' => false,
            'is_multi_value' => false,
            'is_localizable' => false,
            'external_scope' => 'global',
            'normalized_payload' => $normalizedPayload,
            'canonical_hash' => hash('sha256', 'field-color'),
            'sort_order' => 1,
        ]);
    }
}
