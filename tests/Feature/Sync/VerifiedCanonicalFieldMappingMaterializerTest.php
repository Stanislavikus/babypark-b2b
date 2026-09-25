<?php

namespace Tests\Feature\Sync;

use App\Enums\ConnectorSchemaFieldDisposition;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorSchemaFieldClassification;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\FieldMapping;
use App\Services\Sync\VerifiedCanonicalFieldMappingMaterializer;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\FieldDefinitionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

class VerifiedCanonicalFieldMappingMaterializerTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(FieldDefinitionSeeder::class);
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import],
        ]);
    }

    public function test_materializes_only_classifier_approved_verified_canonical_mappings_and_is_idempotent(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $snapshot = $this->publishAuthoritativeSnapshot($account, [
            'name', 'sku', 'description', 'status', 'short_description',
        ]);
        $snapshot->forceFill(['canonical_hash_version' => 'v2'])->save();

        foreach (['name', 'sku', 'description', 'status'] as $key) {
            $this->classification($account->id, $account->workspace_id, $snapshot->connector_schema_source_id, $key,
                ConnectorSchemaFieldDisposition::CanonicalPlatform, 'verified_channel_mapping_rule');
        }
        $this->classification($account->id, $account->workspace_id, $snapshot->connector_schema_source_id,
            'short_description', ConnectorSchemaFieldDisposition::ProviderStandard, 'canonical_target_not_ready');

        $first = app(VerifiedCanonicalFieldMappingMaterializer::class)
            ->materialize($account->workspace_id, $account->id);

        $this->assertSame(1, $first['configurations']);
        $this->assertSame(4, $first['candidates']);
        $this->assertSame(4, $first['materialized']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame(4, FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)->count());
        $this->assertFalse(FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('external_field_key', 'short_description')->exists());
        $second = app(VerifiedCanonicalFieldMappingMaterializer::class)
            ->materialize($account->workspace_id, $account->id);

        $this->assertSame(1, $second['configurations']);
        $this->assertSame(0, $second['candidates']);
        $this->assertSame(0, $second['materialized']);
        $this->assertSame(0, $second['skipped']);
        $this->assertSame(4, FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)->count());
    }

    public function test_products_configuration_created_after_v2_discovery_materializes_verified_mappings_automatically(): void
    {
        $account = $this->createSyncSupportAccount();
        $snapshot = $this->publishAuthoritativeSnapshot($account, ['name', 'description']);
        $snapshot->forceFill(['canonical_hash_version' => 'v2'])->save();

        foreach (['name', 'description'] as $key) {
            $this->classification(
                $account->id,
                $account->workspace_id,
                $snapshot->connector_schema_source_id,
                $key,
                ConnectorSchemaFieldDisposition::CanonicalPlatform,
                'verified_channel_mapping_rule',
            );
        }

        $configuration = $this->createProductsSyncConfiguration($account);

        $this->assertSame(2, FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->count());
        $this->assertSame(
            ['description', 'name'],
            FieldMapping::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configuration->id)
                ->orderBy('external_field_key')
                ->pluck('external_field_key')
                ->all(),
        );
    }

    public function test_review_needed_field_never_becomes_auto_mapping_candidate_on_v2_snapshot(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $snapshot = $this->publishAuthoritativeSnapshot($account, ['name']);
        $snapshot->forceFill(['canonical_hash_version' => 'v2'])->save();

        $this->classification($account->id, $account->workspace_id, $snapshot->connector_schema_source_id,
            'name', ConnectorSchemaFieldDisposition::ReviewNeeded, 'none_review_required');

        $result = app(VerifiedCanonicalFieldMappingMaterializer::class)
            ->materialize($account->workspace_id, $account->id);

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)->count());
    }

    private function classification(
        string $accountId,
        string $workspaceId,
        string $schemaSourceId,
        string $externalFieldKey,
        ConnectorSchemaFieldDisposition $disposition,
        string $mappingStrategy,
    ): void {
        $field = ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('external_field_key', $externalFieldKey)
            ->latest('created_at')
            ->firstOrFail();

        ConnectorSchemaFieldClassification::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'connector_account_id' => $accountId,
            'connector_schema_source_id' => $schemaSourceId,
            'external_field_key' => $externalFieldKey,
            'latest_snapshot_field_id' => $field->id,
            'disposition' => $disposition,
            'behavior_class' => 'test.behavior',
            'behavior_signature' => ['provider' => 'adobe_commerce'],
            'runtime_owner_hint' => null,
            'canonical_code' => $disposition === ConnectorSchemaFieldDisposition::CanonicalPlatform
                ? $externalFieldKey : null,
            'mapping_strategy' => $mappingStrategy,
            'classifier_version' => 'test.v1',
            'reason_code' => 'test',
            'classified_canonical_hash' => $field->canonical_hash,
            'computed_at' => now(),
        ]);
    }
}
