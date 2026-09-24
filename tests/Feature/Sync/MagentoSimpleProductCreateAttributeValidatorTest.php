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
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredState;
use App\Support\Connectors\AdobePaaS\Command\AdobeSimpleProductCreateAttributeValidator;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

final class MagentoSimpleProductCreateAttributeValidatorTest extends TestCase
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
    public function unmapped_required_simple_attribute_blocks_create_before_external_write(): void
    {
        [$account, $source, $snapshot, $set] = $this->structure();
        $this->attribute(
            $account->workspace_id,
            $account->id,
            $source,
            $snapshot,
            $set,
            501,
            'merchant_required_code',
            required: true,
            applyTo: ['simple'],
        );

        $result = app(AdobeSimpleProductCreateAttributeValidator::class)->validate(
            $account->workspace_id,
            $account->id,
            $this->desiredState(),
        );

        $this->assertFalse($result->ready);
        $this->assertSame('adobe_create_required_attributes_missing', $result->reasonCode);
        $this->assertSame(['merchant_required_code'], $result->attributeCodes);
    }

    #[Test]
    public function provider_default_satisfies_required_attribute_and_non_simple_requirement_is_ignored(): void
    {
        [$account, $source, $snapshot, $set] = $this->structure();
        $this->attribute(
            $account->workspace_id,
            $account->id,
            $source,
            $snapshot,
            $set,
            502,
            'tax_class_id',
            required: true,
            defaultValue: '2',
            applyTo: ['simple'],
        );
        $this->attribute(
            $account->workspace_id,
            $account->id,
            $source,
            $snapshot,
            $set,
            503,
            'configurable_only_required',
            required: true,
            applyTo: ['configurable'],
        );
        $this->attribute(
            $account->workspace_id,
            $account->id,
            $source,
            $snapshot,
            $set,
            505,
            'created_at',
            required: true,
        );
        $this->attribute(
            $account->workspace_id,
            $account->id,
            $source,
            $snapshot,
            $set,
            506,
            'updated_at',
            required: true,
        );

        $result = app(AdobeSimpleProductCreateAttributeValidator::class)->validate(
            $account->workspace_id,
            $account->id,
            $this->desiredState(),
        );

        $this->assertTrue($result->ready);
        $this->assertSame('adobe_create_preflight_ready', $result->reasonCode);
    }

    #[Test]
    public function mapped_required_select_must_reference_a_current_provider_option(): void
    {
        [$account, $source, $snapshot, $set] = $this->structure();
        $lineage = $this->attribute(
            $account->workspace_id,
            $account->id,
            $source,
            $snapshot,
            $set,
            504,
            'merchant_color',
            required: true,
            frontendInput: 'select',
            applyTo: ['simple'],
        );

        AdobeProductAttributeOptionLineage::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'adobe_product_attribute_lineage_id' => $lineage->id,
            'provider_option_id' => '93',
            'default_label' => 'Red',
            'labels_by_store' => ['default' => 'Red'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);

        $desired = $this->desiredState(['merchant_color' => '999']);

        $result = app(AdobeSimpleProductCreateAttributeValidator::class)->validate(
            $account->workspace_id,
            $account->id,
            $desired,
        );

        $this->assertFalse($result->ready);
        $this->assertSame('adobe_create_attribute_option_invalid', $result->reasonCode);
        $this->assertSame(['merchant_color'], $result->attributeCodes);
    }

    /**
     * @return array{0: object, 1: ConnectorSchemaSource, 2: ConnectorSchemaSnapshot, 3: AdobeProductAttributeSet}
     */
    private function structure(): array
    {
        $account = $this->createConnectorAccount();
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $account->connector_definition_id)
            ->where('code', 'live_account_attributes')
            ->firstOrFail();

        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => 'manual',
            'status' => 'succeeded',
            'execution_attempts' => 1,
            'started_at' => now(),
            'finished_at' => now(),
            'fields_received' => 10,
            'fields_identified' => 10,
            'fields_normalized' => 10,
            'fields_unclassified' => 0,
        ]);

        $snapshot = ConnectorSchemaSnapshot::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'discovery_run_id' => $run->id,
            'previous_snapshot_id' => null,
            'schema_version' => $source->schema_version,
            'field_count' => 10,
            'canonical_hash' => hash('sha256', (string) Str::uuid()),
            'canonical_hash_version' => 'v2',
            'captured_at' => now(),
        ]);

        $set = AdobeProductAttributeSet::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_set_id' => 9,
            'name' => 'Create Set',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);

        return [$account, $source, $snapshot, $set];
    }

    private function attribute(
        string $workspaceId,
        string $connectorAccountId,
        ConnectorSchemaSource $source,
        ConnectorSchemaSnapshot $snapshot,
        AdobeProductAttributeSet $set,
        int $providerId,
        string $code,
        bool $required,
        string $frontendInput = 'text',
        mixed $defaultValue = null,
        array $applyTo = [],
    ): AdobeProductAttributeLineage {
        $field = ConnectorSchemaSnapshotField::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'snapshot_id' => $snapshot->id,
            'external_field_key' => $code,
            'external_label' => $code,
            'normalization_status' => 'normalized',
            'normalization_failure_reason' => null,
            'normalized_data_type' => $frontendInput === 'select' ? 'select' : 'text',
            'is_required' => $required,
            'is_multi_value' => false,
            'is_localizable' => false,
            'external_scope' => 'global',
            'normalized_payload' => [
                'provider_metadata' => [
                    'frontend_input' => $frontendInput,
                    'scope' => 'global',
                    'default_value' => $defaultValue,
                    'apply_to' => $applyTo,
                ],
                'provider_metadata_version' => 'adobe.product_attribute.v1',
            ],
            'canonical_hash' => hash('sha256', $snapshot->id.':'.$code),
            'sort_order' => $providerId,
        ]);

        ConnectorSchemaFieldClassification::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'connector_schema_source_id' => $source->id,
            'external_field_key' => $code,
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

        $lineage = AdobeProductAttributeLineage::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_id' => $providerId,
            'last_external_field_key' => $code,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);

        AdobeProductAttributeSetMembership::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'connector_schema_source_id' => $source->id,
            'adobe_product_attribute_lineage_id' => $lineage->id,
            'adobe_product_attribute_set_id' => $set->id,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);

        return $lineage;
    }

    private function desiredState(array $customAttributes = []): AdobeProductDesiredState
    {
        return new AdobeProductDesiredState(
            productVariantId: '101',
            sku: 'CREATE-VALIDATION-SKU',
            name: 'Create Validation Product',
            attributeSetId: 9,
            typeId: 'simple',
            status: 1,
            visibility: 4,
            price: 100.0,
            priceCurrency: 'UAH',
            customAttributes: $customAttributes,
        );
    }
}
