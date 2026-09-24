<?php

namespace Tests\Feature\Sync;

use App\Models\AdobeProductAttributeLineage;
use App\Models\AdobeProductAttributeOptionLineage;
use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetMembership;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaFieldClassification;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\ConnectorSchemaSource;
use App\Support\Connectors\AdobePaaS\AdobeProductExportRunMetadataPreparer;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

final class AdobeProductExportPersistedMetadataTest extends TestCase
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
    public function decision_b_run_metadata_uses_reconciled_structure_for_multiple_sets_without_http(): void
    {
        $account = $this->createConnectorAccount();
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $account->connector_definition_id)
            ->where('code', 'live_account_attributes')
            ->firstOrFail();
        $snapshot = $this->schemaSnapshot($account->workspace_id, $account->id, $source, 2);

        $nameField = $this->snapshotField(
            $account->workspace_id,
            $snapshot->id,
            'name',
            'Name',
            'text',
            'text',
            true,
        );
        $colorField = $this->snapshotField(
            $account->workspace_id,
            $snapshot->id,
            'color',
            'Color',
            'select',
            'select',
            false,
        );

        $this->classification(
            $account->workspace_id,
            $account->id,
            $source->id,
            $nameField,
            'text',
        );
        $this->classification(
            $account->workspace_id,
            $account->id,
            $source->id,
            $colorField,
            'select',
        );

        $defaultSet = $this->attributeSet(
            $account->workspace_id,
            $account->id,
            $source->id,
            4,
            'Default',
        );
        $babySet = $this->attributeSet(
            $account->workspace_id,
            $account->id,
            $source->id,
            9,
            'Baby',
        );
        $nameLineage = $this->lineage(
            $account->workspace_id,
            $account->id,
            $source->id,
            71,
            'name',
        );
        $colorLineage = $this->lineage(
            $account->workspace_id,
            $account->id,
            $source->id,
            100,
            'color',
        );

        $this->membership(
            $account->workspace_id,
            $account->id,
            $source->id,
            $nameLineage->id,
            $defaultSet->id,
        );
        $this->membership(
            $account->workspace_id,
            $account->id,
            $source->id,
            $colorLineage->id,
            $babySet->id,
        );

        AdobeProductAttributeOptionLineage::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'adobe_product_attribute_lineage_id' => $colorLineage->id,
            'provider_option_id' => '93',
            'default_label' => 'Red',
            'labels_by_store' => ['default' => 'Red'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);

        $transport = new RecordingConnectorHttpTransport(
            static function (ConnectorOutboundRequest $request): ConnectorHttpResult {
                throw new \RuntimeException('Decision-B persisted metadata must not issue provider HTTP: '.(string) $request->request->getUri());
            },
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $metadata = app(AdobeProductExportRunMetadataPreparer::class)->prepareMetadata(
            $account->workspace_id,
            $account->id,
            [
                'connector_execution_configuration' => [],
                'field_mappings' => [
                    ['external_field_key' => 'name'],
                    ['external_field_key' => 'color'],
                ],
                'adobe_product_classifications' => [
                    [
                        'product_id' => '101',
                        'provider_attribute_set_id' => 4,
                    ],
                    [
                        'product_id' => '202',
                        'provider_attribute_set_id' => 9,
                    ],
                ],
            ],
        );

        $this->assertSame([], $transport->recordedRequests);
        $this->assertSame(4, $metadata->selectedAttributeSetId);
        $this->assertTrue($metadata->hasAttributeSet(4));
        $this->assertTrue($metadata->hasAttributeSet(9));

        $name = $metadata->attributeByCode('name');
        $this->assertNotNull($name);
        $this->assertSame(71, $name->attributeId);
        $this->assertSame('text', $name->frontendInput);
        $this->assertSame('global', $name->scope);
        $this->assertSame('Name', $name->defaultFrontendLabel);
        $this->assertTrue($name->isRequired);
        $this->assertNull($metadata->attributeByCode('color'));

        $baby = $metadata->forAttributeSetId(9);
        $color = $baby->attributeByCode('color');
        $this->assertNotNull($color);
        $this->assertSame(100, $color->attributeId);
        $this->assertTrue($baby->isConfigurableCompatible('color'));
        $this->assertTrue($baby->optionExists('color', '93'));
        $this->assertSame('Red', $color->options['93']);
        $this->assertNull($baby->attributeByCode('name'));
    }

    private function schemaSnapshot(
        string $workspaceId,
        string $connectorAccountId,
        ConnectorSchemaSource $source,
        int $fieldCount,
    ): ConnectorSchemaSnapshot {
        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
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
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'connector_schema_source_id' => $source->id,
            'discovery_run_id' => $run->id,
            'previous_snapshot_id' => null,
            'schema_version' => $source->schema_version,
            'field_count' => $fieldCount,
            'canonical_hash' => hash('sha256', (string) Str::uuid()),
            'canonical_hash_version' => 'v2',
            'captured_at' => now(),
        ]);
    }

    private function snapshotField(
        string $workspaceId,
        string $snapshotId,
        string $code,
        string $label,
        string $frontendInput,
        string $normalizedType,
        bool $required,
    ): ConnectorSchemaSnapshotField {
        return ConnectorSchemaSnapshotField::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'snapshot_id' => $snapshotId,
            'external_field_key' => $code,
            'external_label' => $label,
            'normalization_status' => 'normalized',
            'normalization_failure_reason' => null,
            'normalized_data_type' => $normalizedType,
            'is_required' => $required,
            'is_multi_value' => false,
            'is_localizable' => false,
            'external_scope' => 'global',
            'normalized_payload' => [
                'provider_metadata' => [
                    'frontend_input' => $frontendInput,
                    'scope' => 'global',
                ],
                'provider_metadata_version' => 'adobe.product_attribute.v1',
            ],
            'canonical_hash' => hash('sha256', $snapshotId.':'.$code),
            'sort_order' => 1,
        ]);
    }

    private function classification(
        string $workspaceId,
        string $connectorAccountId,
        string $sourceId,
        ConnectorSchemaSnapshotField $field,
        string $frontendInput,
    ): void {
        ConnectorSchemaFieldClassification::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'connector_schema_source_id' => $sourceId,
            'external_field_key' => $field->external_field_key,
            'latest_snapshot_field_id' => $field->id,
            'disposition' => 'provider_standard',
            'behavior_class' => 'adobe.product_attribute.test',
            'behavior_signature' => [
                'provider' => 'adobe_commerce',
                'frontend_input' => $frontendInput,
                'scope' => 'global',
            ],
            'runtime_owner_hint' => null,
            'canonical_code' => null,
            'mapping_strategy' => 'test',
            'classifier_version' => 'test.v1',
            'reason_code' => 'test',
            'classified_canonical_hash' => $field->canonical_hash,
            'computed_at' => now(),
        ]);
    }

    private function attributeSet(
        string $workspaceId,
        string $connectorAccountId,
        string $sourceId,
        int $providerId,
        string $name,
    ): AdobeProductAttributeSet {
        return AdobeProductAttributeSet::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'connector_schema_source_id' => $sourceId,
            'provider_attribute_set_id' => $providerId,
            'name' => $name,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);
    }

    private function lineage(
        string $workspaceId,
        string $connectorAccountId,
        string $sourceId,
        int $providerId,
        string $code,
    ): AdobeProductAttributeLineage {
        return AdobeProductAttributeLineage::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'connector_schema_source_id' => $sourceId,
            'provider_attribute_id' => $providerId,
            'last_external_field_key' => $code,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);
    }

    private function membership(
        string $workspaceId,
        string $connectorAccountId,
        string $sourceId,
        string $lineageId,
        string $setId,
    ): void {
        AdobeProductAttributeSetMembership::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'connector_schema_source_id' => $sourceId,
            'adobe_product_attribute_lineage_id' => $lineageId,
            'adobe_product_attribute_set_id' => $setId,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);
    }
}
