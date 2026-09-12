<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorSchemaFieldDisposition;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaFieldClassification;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSource;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorSchemaFieldClassificationProjector;
use Database\Seeders\ConnectorFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConnectorSchemaFieldClassificationProjectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_one_thousand_fields_without_literal_key_engineering(): void
    {
        [$account, $source] = $this->context();
        $snapshot = $this->snapshot($account, $source, 1000);

        $canonicalKeys = ['name', 'sku', 'status', 'description', 'short_description'];
        $rows = [];
        foreach ($canonicalKeys as $key) {
            $rows[] = $this->fieldRow($account, $snapshot, $key, isUserDefined: false);
        }
        for ($index = 0; $index < 995; $index++) {
            $rows[] = $this->fieldRow($account, $snapshot, sprintf('merchant_field_%04d', $index));
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('connector_schema_snapshot_fields')->insert($chunk);
        }

        app(ConnectorSchemaFieldClassificationProjector::class)->project($account, $source, $snapshot);

        $query = ConnectorSchemaFieldClassification::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('connector_schema_source_id', $source->id);

        $this->assertSame(1000, (clone $query)->count());
        $this->assertSame(5, (clone $query)->where('disposition', ConnectorSchemaFieldDisposition::CanonicalPlatform->value)->count());
        $this->assertSame(995, (clone $query)->where('disposition', ConnectorSchemaFieldDisposition::WorkspaceCustom->value)->count());
        $this->assertSame(0, (clone $query)->where('disposition', ConnectorSchemaFieldDisposition::ReviewNeeded->value)->count());
        $this->assertSame(1, (clone $query)->where('disposition', ConnectorSchemaFieldDisposition::WorkspaceCustom->value)->distinct()->count('behavior_class'));
    }

    public function test_reclassification_preserves_identity_changes_behavior_and_removes_stale_current_rows(): void
    {
        [$account, $source] = $this->context();
        $firstSnapshot = $this->snapshot($account, $source, 2);
        DB::table('connector_schema_snapshot_fields')->insert([
            $this->fieldRow($account, $firstSnapshot, 'merchant_choice'),
            $this->fieldRow($account, $firstSnapshot, 'merchant_removed'),
        ]);
        $projector = app(ConnectorSchemaFieldClassificationProjector::class);
        $projector->project($account, $source, $firstSnapshot);

        $before = ConnectorSchemaFieldClassification::withoutWorkspaceScope()
            ->where('connector_account_id', $account->id)
            ->where('external_field_key', 'merchant_choice')
            ->firstOrFail();

        $secondSnapshot = $this->snapshot($account, $source, 1, $firstSnapshot->id);
        $secondField = $this->fieldRow(
            $account,
            $secondSnapshot,
            'merchant_choice',
            frontendInput: 'multiselect',
            normalizedType: 'multi_select',
            multi: true,
        );
        DB::table('connector_schema_snapshot_fields')->insert($secondField);
        $projector->project($account, $source, $secondSnapshot);

        $after = ConnectorSchemaFieldClassification::withoutWorkspaceScope()
            ->where('connector_account_id', $account->id)
            ->where('external_field_key', 'merchant_choice')
            ->firstOrFail();

        $this->assertSame($before->id, $after->id);
        $this->assertNotSame($before->behavior_class, $after->behavior_class);
        $this->assertSame($secondSnapshot->id, $after->latestSnapshotField->snapshot_id);
        $this->assertSame($secondField['id'], $after->latest_snapshot_field_id);
        $this->assertSame($secondField['canonical_hash'], $after->classified_canonical_hash);
        $this->assertNotNull($after->computed_at);
        $this->assertFalse(ConnectorSchemaFieldClassification::withoutWorkspaceScope()
            ->where('connector_account_id', $account->id)
            ->where('external_field_key', 'merchant_removed')
            ->exists());
    }

    public function test_projection_schema_keeps_readiness_derived_and_avoids_duplicate_snapshot_identity(): void
    {
        $this->assertTrue(Schema::hasColumns('connector_schema_field_classifications', [
            'workspace_id',
            'connector_account_id',
            'connector_schema_source_id',
            'external_field_key',
            'latest_snapshot_field_id',
            'disposition',
            'behavior_class',
            'behavior_signature',
            'runtime_owner_hint',
            'canonical_code',
            'mapping_strategy',
            'classifier_version',
            'reason_code',
            'classified_canonical_hash',
            'computed_at',
        ]));
        $this->assertFalse(Schema::hasColumn('connector_schema_field_classifications', 'latest_snapshot_id'));
        $this->assertFalse(Schema::hasColumn('connector_schema_field_classifications', 'mapping_readiness'));
    }

    public function test_projection_identity_includes_schema_source(): void
    {
        [$account, $source] = $this->context();
        $otherSource = $source->replicate();
        $otherSource->id = null;
        $otherSource->code = 'live_account_attributes_secondary';
        $otherSource->is_primary = false;
        $otherSource->save();

        $firstSnapshot = $this->snapshot($account, $source, 1);
        $secondSnapshot = $this->snapshot($account, $otherSource, 1);
        DB::table('connector_schema_snapshot_fields')->insert([
            $this->fieldRow($account, $firstSnapshot, 'same_external_key'),
            $this->fieldRow($account, $secondSnapshot, 'same_external_key'),
        ]);

        $projector = app(ConnectorSchemaFieldClassificationProjector::class);
        $projector->project($account, $source, $firstSnapshot);
        $projector->project($account, $otherSource, $secondSnapshot);

        $rows = ConnectorSchemaFieldClassification::withoutWorkspaceScope()
            ->where('connector_account_id', $account->id)
            ->where('external_field_key', 'same_external_key')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows->pluck('connector_schema_source_id')->unique()->count());
    }

    /** @return array{ConnectorAccount, ConnectorSchemaSource} */
    private function context(): array
    {
        $this->seed(ConnectorFoundationSeeder::class);
        $workspace = Workspace::query()->where('is_default', true)->first()
            ?? Workspace::query()->create(['name' => 'Classification Workspace', 'is_default' => true]);
        $definition = ConnectorDefinition::query()->where('code', 'adobe_commerce')->firstOrFail();
        $account = ConnectorAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'connector_definition_id' => $definition->id,
        ]);
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $definition->id)
            ->where('code', 'live_account_attributes')
            ->firstOrFail();

        return [$account, $source];
    }

    private function snapshot(
        ConnectorAccount $account,
        ConnectorSchemaSource $source,
        int $fieldCount,
        ?string $previousSnapshotId = null,
    ): ConnectorSchemaSnapshot {
        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => 'manual',
            'status' => 'succeeded',
            'execution_attempts' => 1,
            'started_at' => now(),
            'finished_at' => now(),
            'fields_received' => $fieldCount,
            'fields_identified' => $fieldCount,
            'fields_normalized' => $fieldCount,
            'fields_unclassified' => 0,
        ]);

        return ConnectorSchemaSnapshot::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'discovery_run_id' => $run->id,
            'previous_snapshot_id' => $previousSnapshotId,
            'schema_version' => $source->schema_version,
            'field_count' => $fieldCount,
            'canonical_hash' => hash('sha256', (string) Str::uuid()),
            'canonical_hash_version' => 'v2',
            'captured_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function fieldRow(
        ConnectorAccount $account,
        ConnectorSchemaSnapshot $snapshot,
        string $key,
        string $frontendInput = 'select',
        string $normalizedType = 'select',
        bool $multi = false,
        bool $isUserDefined = true,
    ): array {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'snapshot_id' => $snapshot->id,
            'external_field_key' => $key,
            'external_label' => $key,
            'normalization_status' => 'normalized',
            'normalization_failure_reason' => null,
            'normalized_data_type' => $normalizedType,
            'is_required' => false,
            'is_multi_value' => $multi,
            'is_localizable' => false,
            'external_scope' => 'global',
            'normalized_payload' => json_encode([
                'options' => [['value' => '1'], ['value' => '2']],
                'provider_metadata' => [
                    'frontend_input' => $frontendInput,
                    'scope' => 'global',
                    'backend_type' => 'int',
                    'source_model' => 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Table',
                    'backend_model' => null,
                    'is_user_defined' => $isUserDefined,
                    'apply_to' => [],
                ],
                'provider_metadata_version' => 'adobe.product_attribute.v1',
            ], JSON_THROW_ON_ERROR),
            'canonical_hash' => hash('sha256', $key.$snapshot->id),
            'sort_order' => 1,
            'created_at' => now(),
        ];
    }
}
