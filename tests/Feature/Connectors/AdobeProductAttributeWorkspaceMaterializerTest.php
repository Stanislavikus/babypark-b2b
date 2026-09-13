<?php

namespace Tests\Feature\Connectors;

use App\Enums\AttributeDataType;
use App\Enums\AttributeStorageType;
use App\Enums\ConnectorSchemaFieldDisposition;
use App\Enums\FieldObjectType;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\AdobeProductAttributeLineage;
use App\Models\AdobeProductAttributeMaterialization;
use App\Models\AdobeProductAttributeOptionLineage;
use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetMembership;
use App\Models\ConnectorSchemaFieldClassification;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\ExternalRecordLink;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;
use App\Models\FieldOptionMapping;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Connectors\AdobeProductAttributeWorkspaceMaterializer;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Services\Sync\SyncConfigurationService;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Sync\ConnectorExecutionConfiguration;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\FieldDefinitionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class AdobeProductAttributeWorkspaceMaterializerTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use CreatesMerchantConfirmedExternalRecordLinks;
    use InteractsWithFieldMappingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([WorkspaceSeeder::class, FieldDefinitionSeeder::class, ConnectorFoundationSeeder::class]);
        $this->configureAdobePaaSSyncSupportProfile([[SyncDataDomain::Products, SyncSemanticOperation::Import]]);
    }

    #[Test]
    public function trusted_variant_select_materializes_idempotently_with_mapping_and_options(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $configuration = app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 9]),
        );

        $snapshot = $this->publishAuthoritativeSnapshotWithOptions($account, [
            'merchant_color' => [
                ['value' => '10', 'label' => 'Red'],
                ['value' => '20', 'label' => 'Blue'],
            ],
        ]);
        $snapshot->forceFill(['canonical_hash_version' => 'v2'])->save();
        $snapshotField = ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('snapshot_id', $snapshot->id)
            ->where('external_field_key', 'merchant_color')
            ->firstOrFail();
        $snapshotField->forceFill([
            'external_label' => 'Merchant Color',
            'normalized_data_type' => 'select',
            'is_multi_value' => false,
            'is_localizable' => false,
            'external_scope' => 'global',
        ])->save();

        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);
        ConnectorSchemaFieldClassification::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'external_field_key' => 'merchant_color',
            'latest_snapshot_field_id' => $snapshotField->id,
            'disposition' => ConnectorSchemaFieldDisposition::WorkspaceCustom,
            'behavior_class' => 'test.select',
            'behavior_signature' => ['provider' => 'adobe_commerce'],
            'runtime_owner_hint' => null,
            'canonical_code' => null,
            'mapping_strategy' => 'workspace_custom_deferred',
            'classifier_version' => 'test.v1',
            'reason_code' => 'merchant_defined_attribute',
            'classified_canonical_hash' => $snapshotField->canonical_hash,
            'computed_at' => now(),
        ]);

        $lineage = AdobeProductAttributeLineage::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_id' => 100,
            'last_external_field_key' => 'merchant_color',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);
        $set = AdobeProductAttributeSet::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_set_id' => 9,
            'name' => 'Baby',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);
        AdobeProductAttributeSetMembership::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'adobe_product_attribute_lineage_id' => $lineage->id,
            'adobe_product_attribute_set_id' => $set->id,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);
        foreach ([['10', 'Red'], ['20', 'Blue']] as [$value, $label]) {
            AdobeProductAttributeOptionLineage::withoutWorkspaceScope()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'connector_account_id' => $account->id,
                'connector_schema_source_id' => $source->id,
                'adobe_product_attribute_lineage_id' => $lineage->id,
                'provider_option_id' => $value,
                'default_label' => $label,
                'labels_by_store' => ['default' => $label],
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'missing_since' => null,
            ]);
        }

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-V-P',
            'name' => 'Local Product',
            'is_active' => true,
        ]);
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-V',
            'is_active' => true,
        ]);
        ExternalRecordLink::withoutWorkspaceScope()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace,
            $account->id,
            $variant,
            'SKU-V',
            '77',
        ));

        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            $this->assertStringContainsString('/V1/products/SKU-V', (string) $request->request->getUri());

            return new ConnectorHttpResult(200, [], json_encode([
                'id' => 77,
                'sku' => 'SKU-V',
                'name' => 'Remote Variant',
                'attribute_set_id' => 9,
                'type_id' => 'simple',
                'status' => 1,
                'visibility' => 1,
                'price' => 100,
                'custom_attributes' => [
                    ['attribute_code' => 'merchant_color', 'value' => '10'],
                ],
            ], JSON_THROW_ON_ERROR));
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $first = app(AdobeProductAttributeWorkspaceMaterializer::class)->materialize($workspace->id, $account->id);
        $materialization = AdobeProductAttributeMaterialization::withoutWorkspaceScope()->sole();
        $definition = FieldDefinition::withoutWorkspaceScope()->findOrFail($materialization->field_definition_id);
        $binding = FieldBinding::withoutWorkspaceScope()->where('field_definition_id', $definition->id)->sole();
        $mapping = FieldMapping::withoutWorkspaceScope()->where('field_binding_id', $binding->id)->sole();

        $this->assertSame(1, $first->eligibleFields);
        $this->assertSame(1, $first->definitions);
        $this->assertSame(1, $first->bindings);
        $this->assertSame(1, $first->fieldMappings);
        $this->assertSame(2, $first->optionMappings);
        $this->assertSame('merchant_color', $definition->code);
        $this->assertSame(AttributeDataType::Select, $definition->data_type);
        $this->assertSame(FieldObjectType::ProductVariant, $binding->object_type);
        $this->assertSame(AttributeStorageType::Dynamic, $binding->storage_type);
        $this->assertSame('merchant_color', $mapping->external_field_key);
        $this->assertSame(['10', '20'], FieldOptionMapping::withoutWorkspaceScope()
            ->where('field_mapping_id', $mapping->id)->orderBy('internal_option_key')->pluck('internal_option_key')->all());

        $firstIds = [$materialization->id, $definition->id, $binding->id, $mapping->id];
        $second = app(AdobeProductAttributeWorkspaceMaterializer::class)->materialize($workspace->id, $account->id);

        $this->assertSame(1, $second->eligibleFields);
        $this->assertSame(0, $second->definitions);
        $this->assertSame(0, $second->bindings);
        $this->assertSame(1, AdobeProductAttributeMaterialization::withoutWorkspaceScope()->count());
        $this->assertSame(1, FieldDefinition::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('code', 'merchant_color')->count());
        $this->assertSame(1, FieldBinding::withoutWorkspaceScope()->where('field_definition_id', $definition->id)->count());
        $this->assertSame(1, FieldMapping::withoutWorkspaceScope()->where('field_binding_id', $binding->id)->count());
        $this->assertSame($firstIds, [
            AdobeProductAttributeMaterialization::withoutWorkspaceScope()->sole()->id,
            FieldDefinition::withoutWorkspaceScope()->findOrFail($definition->id)->id,
            FieldBinding::withoutWorkspaceScope()->where('field_definition_id', $definition->id)->sole()->id,
            FieldMapping::withoutWorkspaceScope()->where('field_binding_id', $binding->id)->sole()->id,
        ]);
        $this->assertSame(2, $transport->sendCount);
    }
}
