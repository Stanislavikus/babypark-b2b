<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use App\Services\Connectors\ConnectorDiscoveryRunPersistence;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Support\Connectors\CanonicalSchemaField;
use App\Support\Connectors\CanonicalSchemaFieldHash;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaPayload;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\ConnectorDiscoveryNormalizedField;
use App\Support\Connectors\ConnectorDiscoverySnapshotCandidate;
use Carbon\CarbonImmutable;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertDatabaseJsonTypes($field->id, expectsOptionsArray: false);
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
        $this->assertDatabaseJsonTypes($field->id, expectsOptionsArray: true);
    }

    #[Test]
    public function finalize_after_vendor_attempt_persists_empty_object_payload_with_database_json_types(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
        $field = $this->buildNormalizedField(
            externalFieldKey: 'empty_payload',
            payload: CanonicalSchemaPayload::empty(),
            sortOrder: null,
        );

        $this->publishThroughFinalizeAfterVendorAttempt($account, $row, [$field]);

        $persisted = ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('snapshot_id', $row->fresh()->snapshot_id)
            ->where('external_field_key', 'empty_payload')
            ->firstOrFail();

        $this->assertNull($persisted->sort_order);
        $this->assertSame('{}', json_encode($persisted->normalized_payload, JSON_THROW_ON_ERROR));
        $this->assertDatabaseJsonTypes($persisted->id, expectsOptionsArray: false);
    }

    #[Test]
    public function finalize_after_vendor_attempt_persists_empty_options_payload_with_database_json_types(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
        $field = $this->buildNormalizedField(
            externalFieldKey: 'options_payload',
            payload: CanonicalSchemaPayload::withOptions([]),
            sortOrder: 5,
        );

        $this->publishThroughFinalizeAfterVendorAttempt($account, $row, [$field]);

        $persisted = ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('snapshot_id', $row->fresh()->snapshot_id)
            ->where('external_field_key', 'options_payload')
            ->firstOrFail();

        $this->assertSame(5, $persisted->sort_order);
        $this->assertSame('{"options":[]}', json_encode($persisted->normalized_payload, JSON_THROW_ON_ERROR));
        $this->assertDatabaseJsonTypes($persisted->id, expectsOptionsArray: true);
    }

    #[Test]
    public function canonical_null_sort_order_remains_sql_null_after_publication(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);
        $field = $this->buildNormalizedField(
            externalFieldKey: 'no_position',
            payload: CanonicalSchemaPayload::empty(),
            sortOrder: null,
        );

        $this->publishThroughFinalizeAfterVendorAttempt($account, $row, [$field]);

        $sortOrder = DB::table('connector_schema_snapshot_fields')
            ->where('external_field_key', 'no_position')
            ->value('sort_order');

        $this->assertNull($sortOrder);
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

    private function createQueuedRow(ConnectorAccount $account): ConnectorDiscoveryRun
    {
        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);

        return ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => 'manual',
            'status' => ConnectorDiscoveryRunStatus::Running,
            'execution_attempts' => 1,
            'retry_until_at' => now()->addHour(),
            'next_attempt_at' => null,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  list<ConnectorDiscoveryNormalizedField>  $fields
     */
    private function publishThroughFinalizeAfterVendorAttempt(
        ConnectorAccount $account,
        ConnectorDiscoveryRun $row,
        array $fields,
    ): void {
        $fieldHasher = new CanonicalSchemaFieldHasher;
        $snapshotHasher = new CanonicalSchemaSnapshotHasher;
        $fieldHashes = array_map(
            fn (ConnectorDiscoveryNormalizedField $field): CanonicalSchemaFieldHash => CanonicalSchemaFieldHash::create(
                $field->field->externalFieldKey(),
                $field->canonicalHash,
            ),
            $fields,
        );

        $candidate = ConnectorDiscoverySnapshotCandidate::create(
            $fields,
            $snapshotHasher->hash($fieldHashes),
            CarbonImmutable::now(),
            count($fields),
        );

        $result = ConnectorDiscoveryAttemptResult::success($candidate);

        app(ConnectorDiscoveryRunPersistence::class)->finalizeAfterVendorAttempt(
            $account->workspace_id,
            $account->id,
            $row->id,
            $result,
            10,
            now()->addHour(),
        );
    }

    private function buildNormalizedField(
        string $externalFieldKey,
        CanonicalSchemaPayload $payload,
        ?int $sortOrder,
    ): ConnectorDiscoveryNormalizedField {
        $fieldHasher = new CanonicalSchemaFieldHasher;
        $canonicalField = CanonicalSchemaField::create(
            $externalFieldKey,
            ucfirst($externalFieldKey),
            'string',
            false,
            false,
            false,
            'global',
            $payload,
            $sortOrder,
        );

        return new ConnectorDiscoveryNormalizedField(
            $canonicalField,
            $fieldHasher->hash($canonicalField),
        );
    }

    private function assertDatabaseJsonTypes(string $fieldId, bool $expectsOptionsArray): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $rootType = DB::selectOne(
                'select json_type(normalized_payload) as payload_type from connector_schema_snapshot_fields where id = ?',
                [$fieldId],
            )->payload_type;
            $this->assertSame('object', $rootType);

            if ($expectsOptionsArray) {
                $optionsType = DB::selectOne(
                    'select json_type(normalized_payload, \'$.options\') as options_type from connector_schema_snapshot_fields where id = ?',
                    [$fieldId],
                )->options_type;
                $this->assertSame('array', $optionsType);
            }

            return;
        }

        if ($driver === 'mysql') {
            $rootType = DB::selectOne(
                'select JSON_TYPE(normalized_payload) as payload_type from connector_schema_snapshot_fields where id = ?',
                [$fieldId],
            )->payload_type;
            $this->assertSame('OBJECT', $rootType);

            if ($expectsOptionsArray) {
                $optionsType = DB::selectOne(
                    'select JSON_TYPE(JSON_EXTRACT(normalized_payload, \'$.options\')) as options_type from connector_schema_snapshot_fields where id = ?',
                    [$fieldId],
                )->options_type;
                $this->assertSame('ARRAY', $optionsType);
            }

            return;
        }

        $this->markTestSkipped('Database JSON type assertions are only defined for sqlite and mysql.');
    }
}
