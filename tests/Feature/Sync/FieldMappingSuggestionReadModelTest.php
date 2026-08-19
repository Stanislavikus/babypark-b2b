<?php

namespace Tests\Feature\Sync;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorSchemaAcquisitionMode;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Enums\ConnectorSchemaVerificationStatus;
use App\Enums\FieldObjectType;
use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSource;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Models\Workspace;
use App\Services\Connectors\AuthoritativeConnectorSchemaSnapshotResolver;
use App\Services\Sync\CanonicalFieldMappingSuggestionProvider;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\FieldMappingReadModelProjector;
use App\Support\CanonicalRegistry\CanonicalRegistryReader;
use App\Support\Sync\Exceptions\FieldMappingProjectionInvariantException;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\FieldMappingReadModel\FieldMappingInternalRow;
use App\Support\Sync\FieldMappingReadModel\FieldMappingReadModel;
use App\Support\Workspace\WorkspaceContext;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

class FieldMappingSuggestionReadModelTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use RefreshDatabase;

    private string $tempRegistryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seedFieldDefinitions();
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview],
        ]);

        $this->tempRegistryPath = storage_path('framework/testing/canonical-registry-'.Str::random(8));
        File::ensureDirectoryExists($this->tempRegistryPath);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempRegistryPath)) {
            File::deleteDirectory($this->tempRegistryPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function adobe_sku_canonical_mapping_produces_suggestion_when_snapshot_contains_sku(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['sku', 'description']);

        $model = $this->project($account, $configuration);

        $skuRow = $this->rowForCode($model, 'sku', FieldObjectType::ProductVariant);

        $this->assertTrue($model->discoveryAvailable);
        $this->assertSame('sku', $skuRow->suggestedExternalFieldKey);
        $this->assertNull($skuRow->existingExternalFieldKey);
    }

    #[Test]
    public function adobe_description_does_not_suggest_description_due_to_transport_path_mismatch(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['sku', 'description']);

        $model = $this->project($account, $configuration);

        $descriptionRow = $this->rowForCode($model, 'description', FieldObjectType::Product);

        $this->assertNull($descriptionRow->suggestedExternalFieldKey);
    }

    #[Test]
    public function connector_without_registry_channel_yields_valid_projection_with_zero_suggestions(): void
    {
        $oneCDefinition = ConnectorDefinition::query()->where('code', '1c')->firstOrFail();
        $this->ensurePrimaryAccountDiscoverySource($oneCDefinition);
        $account = $this->createConnectorAccount(null, [
            'connector_definition_id' => $oneCDefinition->id,
            'auth_profile' => 'test_sync_support',
        ]);
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['sku', 'name']);

        $model = $this->project($account, $configuration);

        $this->assertTrue($model->discoveryAvailable);
        $this->assertNotEmpty($model->internalRows);
        $this->assertSame(
            [],
            array_filter(
                $model->internalRows,
                fn ($row) => $row->suggestedExternalFieldKey !== null,
            ),
        );
    }

    #[Test]
    public function projection_performs_no_writes_and_does_not_change_configuration_revision(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['sku', 'name']);

        $revisionBefore = $configuration->configuration_revision;
        $mappingCountBefore = FieldMapping::withoutWorkspaceScope()->count();

        $this->project($account, $configuration);

        $this->assertSame($mappingCountBefore, FieldMapping::withoutWorkspaceScope()->count());
        $this->assertSame(
            $revisionBefore,
            SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision,
        );
    }

    #[Test]
    public function field_definition_eligibility_no_never_yields_binding_suggestion_even_when_definition_exists(): void
    {
        $this->bindCustomRegistry($this->minimalRegistryWithMapping(
            fields: [
                $this->fieldRow('ineligible_price', 'no', 'product_variant', 'system'),
            ],
            mappings: [
                $this->mappingRow('ineligible_price', 'adobe_commerce', 'ineligible_price'),
            ],
        ));

        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => null,
            'code' => 'ineligible_price',
            'data_type' => AttributeDataType::Decimal,
            'scope' => AttributeScope::System,
            'localized_labels' => ['uk' => 'Ціна'],
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        $binding = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => null,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::ProductVariant,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'pricing',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 888,
            'status' => AttributeStatus::Active,
        ]);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['ineligible_price']);

        $model = $this->project($account, $configuration);

        $this->assertNull($this->rowForBindingId($model, $binding->id)->suggestedExternalFieldKey);
    }

    #[Test]
    public function workspace_custom_same_code_definition_does_not_inherit_canonical_suggestion(): void
    {
        $workspace = $this->defaultWorkspace();
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'sku',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Кастомний SKU'],
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        $customBinding = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::ProductVariant,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'identifiers',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 1000,
            'status' => AttributeStatus::Active,
        ]);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['sku']);

        $model = $this->project($account, $configuration);

        $this->assertNull($this->rowForBindingId($model, $customBinding->id)->suggestedExternalFieldKey);
        $this->assertSame(
            'sku',
            $this->rowForCode($model, 'sku', FieldObjectType::ProductVariant)->suggestedExternalFieldKey,
        );
    }

    #[Test]
    public function archived_canonical_definition_or_binding_gets_no_suggestion(): void
    {
        $binding = $this->productVariantBinding('sku');
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['sku']);

        $model = $this->project($account, $configuration);
        $this->assertSame('sku', $this->rowForBindingId($model, $binding->id)->suggestedExternalFieldKey);

        $binding->update(['status' => AttributeStatus::Archived]);

        $archivedBindingSuggestions = app(CanonicalFieldMappingSuggestionProvider::class)->suggest(
            $account->workspace_id,
            'adobe_commerce',
            array_fill_keys(['sku'], true),
            [],
            [],
        );

        $this->assertArrayNotHasKey($binding->id, $archivedBindingSuggestions);

        $binding->update(['status' => AttributeStatus::Active]);
        $binding->fieldDefinition->update(['status' => AttributeStatus::Archived]);

        $archivedDefinitionSuggestions = app(CanonicalFieldMappingSuggestionProvider::class)->suggest(
            $account->workspace_id,
            'adobe_commerce',
            array_fill_keys(['sku'], true),
            [],
            [],
        );

        $this->assertArrayNotHasKey($binding->id, $archivedDefinitionSuggestions);
    }

    #[Test]
    public function binding_strategy_respects_product_vs_product_variant(): void
    {
        $this->bindCustomRegistry($this->minimalRegistryWithMapping(
            fields: [
                $this->fieldRow('sku', 'yes', 'product_variant', 'system'),
            ],
            mappings: [
                $this->mappingRow('sku', 'adobe_commerce', 'sku'),
            ],
        ));

        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['sku']);

        $model = $this->project($account, $configuration);

        $this->assertSame(
            'sku',
            $this->rowForCode($model, 'sku', FieldObjectType::ProductVariant)->suggestedExternalFieldKey,
        );

        $productRowsForSku = array_values(array_filter(
            $model->internalRows,
            fn ($row) => $row->internalFieldCode === 'sku' && $row->objectType === FieldObjectType::Product,
        ));

        $this->assertSame([], $productRowsForSku);
    }

    #[Test]
    public function non_active_or_unverified_canonical_field_gets_no_suggestion(): void
    {
        $this->bindCustomRegistry($this->minimalRegistryWithMapping(
            fields: [
                array_merge($this->fieldRow('material', 'yes', 'product', 'platform_library'), [
                    'status' => 'proposed',
                    'verification_status' => 'partially_verified',
                ]),
            ],
            mappings: [
                $this->mappingRow('material', 'adobe_commerce', 'material'),
            ],
        ));

        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['material']);

        $model = $this->project($account, $configuration);

        $this->assertNull($this->rowForCode($model, 'material', FieldObjectType::Product)->suggestedExternalFieldKey);
    }

    #[Test]
    public function existing_mapping_reserves_binding_and_external_key(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $skuBinding = $this->productVariantBinding('sku');
        $nameBinding = $this->productBinding('name');
        $this->publishAuthoritativeSnapshot($account, ['sku', 'name']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $skuBinding->id,
            'sku',
        );

        $model = $this->project($account, $configuration);

        $skuRow = $this->rowForBindingId($model, $skuBinding->id);
        $nameRow = $this->rowForBindingId($model, $nameBinding->id);

        $this->assertSame('sku', $skuRow->existingExternalFieldKey);
        $this->assertNull($skuRow->suggestedExternalFieldKey);

        foreach ($model->internalRows as $row) {
            if ($row->fieldBindingId !== $skuBinding->id) {
                $this->assertNotSame('sku', $row->suggestedExternalFieldKey);
            }
        }

        $this->assertSame('name', $nameRow->suggestedExternalFieldKey);
    }

    #[Test]
    public function foreign_workspace_canonical_candidate_is_discarded_before_collision_analysis(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Foreign WS '.Str::random(4), 'is_default' => false]);

        $globalDefinition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => null,
            'code' => 'canon_global',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::System,
            'localized_labels' => ['uk' => 'Глобальне поле'],
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        $globalBinding = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => null,
            'field_definition_id' => $globalDefinition->id,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'basic_information',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 10,
            'status' => AttributeStatus::Active,
        ]);

        $foreignDefinition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => null,
            'code' => 'canon_foreign',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::System,
            'localized_labels' => ['uk' => 'Інше поле'],
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        $foreignBinding = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceB->id,
            'field_definition_id' => $foreignDefinition->id,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'basic_information',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 20,
            'status' => AttributeStatus::Active,
        ]);

        $this->bindCustomRegistry($this->minimalRegistryWithMapping(
            fields: [
                $this->fieldRow('canon_global', 'yes', 'product', 'system'),
                $this->fieldRow('canon_foreign', 'yes', 'product', 'system'),
            ],
            mappings: [
                $this->mappingRow('canon_global', 'adobe_commerce', 'shared_key'),
                $this->mappingRow('canon_foreign', 'adobe_commerce', 'shared_key'),
            ],
        ));

        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['shared_key']);

        $suggestions = app(CanonicalFieldMappingSuggestionProvider::class)->suggest(
            $workspaceA->id,
            'adobe_commerce',
            array_fill_keys(['shared_key'], true),
            [],
            [],
        );

        $this->assertArrayNotHasKey($foreignBinding->id, $suggestions);
        $this->assertSame('shared_key', $suggestions[$globalBinding->id]);

        $model = $this->project($account, $configuration);

        $this->assertSame(
            'shared_key',
            $this->rowForBindingId($model, $globalBinding->id)->suggestedExternalFieldKey,
        );
        $this->assertFalse(
            collect($model->internalRows)->contains(
                fn ($row) => $row->fieldBindingId === $foreignBinding->id,
            ),
        );
    }

    #[Test]
    public function forged_foreign_persisted_mapping_fails_projection_closed(): void
    {
        $workspaceA = Workspace::query()->create(['name' => 'Workspace A '.Str::random(4), 'is_default' => false]);
        $workspaceB = Workspace::query()->create(['name' => 'Workspace B '.Str::random(4), 'is_default' => false]);

        $definitionA = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceA->id,
            'code' => 'ws_a_secret_field',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'SECRET_WS_A_LABEL'],
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        $bindingA = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceA->id,
            'field_definition_id' => $definitionA->id,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 999,
            'status' => AttributeStatus::Active,
        ]);

        $accountB = $this->createSyncSupportAccountForWorkspace($workspaceB);
        $configurationB = $this->createProductsSyncConfiguration($accountB);

        FieldMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceB->id,
            'sync_configuration_id' => $configurationB->id,
            'field_binding_id' => $bindingA->id,
            'external_field_key' => 'forged_key',
        ]);

        $this->assertTrue(
            FieldMapping::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configurationB->id)
                ->where('field_binding_id', $bindingA->id)
                ->exists(),
        );

        try {
            $this->project($accountB, $configurationB);
            $this->fail('Projection should fail for forged foreign-workspace persisted mapping.');
        } catch (FieldMappingProjectionInvariantException $exception) {
            $this->assertStringNotContainsString('SECRET_WS_A_LABEL', $exception->getMessage());
            $this->assertStringNotContainsString('ws_a_secret_field', $exception->getMessage());
        }
    }

    #[Test]
    public function two_candidates_for_one_external_key_neither_is_suggested(): void
    {
        $this->bindCustomRegistry($this->minimalRegistryWithMapping(
            fields: [
                $this->fieldRow('name', 'yes', 'product', 'system'),
                $this->fieldRow('brand', 'yes', 'product', 'system'),
            ],
            mappings: [
                $this->mappingRow('name', 'adobe_commerce', 'shared_key'),
                $this->mappingRow('brand', 'adobe_commerce', 'shared_key'),
            ],
        ));

        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['shared_key']);

        $model = $this->project($account, $configuration);

        $this->assertNull($this->rowForCode($model, 'name', FieldObjectType::Product)->suggestedExternalFieldKey);
        $this->assertNull($this->rowForCode($model, 'brand', FieldObjectType::Product)->suggestedExternalFieldKey);
    }

    #[Test]
    public function one_binding_with_two_different_external_keys_gets_no_suggestion(): void
    {
        $this->bindCustomRegistry($this->minimalRegistryWithMapping(
            fields: [
                $this->fieldRow('name', 'yes', 'product', 'system'),
            ],
            mappings: [
                $this->mappingRow('name', 'adobe_commerce', 'name_a'),
                array_merge($this->mappingRow('name', 'adobe_commerce', 'name_b'), [
                    'channel_schema_version' => '2.5.0-admin-rest',
                    'evidence_subject_key' => 'mapping:adobe_commerce:name:name_b:a999:2.5.0-admin-rest',
                ]),
            ],
        ));

        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['name_a', 'name_b']);

        $model = $this->project($account, $configuration);

        $this->assertNull($this->rowForCode($model, 'name', FieldObjectType::Product)->suggestedExternalFieldKey);
    }

    #[Test]
    public function duplicate_canonical_evidence_for_same_pair_does_not_create_false_ambiguity(): void
    {
        $this->bindCustomRegistry($this->minimalRegistryWithMapping(
            fields: [
                $this->fieldRow('name', 'yes', 'product', 'system'),
            ],
            mappings: [
                $this->mappingRow('name', 'adobe_commerce', 'name'),
                array_merge($this->mappingRow('name', 'adobe_commerce', 'name'), [
                    'channel_schema_version' => '2.5.0-admin-rest',
                    'evidence_subject_key' => 'mapping:adobe_commerce:name:name:a888:2.5.0-admin-rest',
                ]),
            ],
        ));

        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['name']);

        $model = $this->project($account, $configuration);

        $this->assertSame(
            'name',
            $this->rowForCode($model, 'name', FieldObjectType::Product)->suggestedExternalFieldKey,
        );
    }

    #[Test]
    public function authoritative_snapshot_resolution_occurs_at_most_once_per_projection(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['sku', 'name']);

        $sourceQueries = 0;
        $snapshotQueries = 0;
        DB::listen(function ($query) use (&$sourceQueries, &$snapshotQueries): void {
            if (str_contains($query->sql, 'connector_schema_sources')) {
                $sourceQueries++;
            }

            if (str_contains(strtolower($query->sql), 'connector_schema_snapshots')) {
                $snapshotQueries++;
            }
        });

        $this->project($account, $configuration);

        $this->assertSame(1, $sourceQueries);
        $this->assertSame(1, $snapshotQueries);
    }

    #[Test]
    public function projection_returns_without_discovery_and_has_no_suggestions_or_external_choices(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);

        $model = $this->project($account, $configuration);

        $this->assertFalse($model->discoveryAvailable);
        $this->assertSame([], $model->discoveredExternalChoices);
        $this->assertSame(
            [],
            array_filter(
                $model->internalRows,
                fn ($row) => $row->suggestedExternalFieldKey !== null,
            ),
        );
    }

    #[Test]
    public function existing_effective_mapping_remains_visible_when_discovery_unavailable(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productVariantBinding('sku');
        $this->publishAuthoritativeSnapshot($account, ['persisted_key']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'persisted_key',
        );

        ConnectorDiscoveryRun::withoutWorkspaceScope()
            ->where('connector_account_id', $account->id)
            ->update(['status' => ConnectorDiscoveryRunStatus::Failed]);

        $model = $this->project($account, $configuration);

        $row = $this->rowForBindingId($model, $binding->id);

        $this->assertFalse($model->discoveryAvailable);
        $this->assertSame('persisted_key', $row->existingExternalFieldKey);
        $this->assertFalse($row->needsAttention);
    }

    #[Test]
    public function stale_external_key_with_discovery_available_needs_attention(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productVariantBinding('sku');
        $this->publishAuthoritativeSnapshot($account, ['stale_key']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'stale_key',
        );

        $this->publishAuthoritativeSnapshot($account, ['other_key']);

        $model = $this->project($account, $configuration);
        $row = $this->rowForBindingId($model, $binding->id);

        $this->assertTrue($model->discoveryAvailable);
        $this->assertSame('stale_key', $row->existingExternalFieldKey);
        $this->assertTrue($row->needsAttention);
    }

    #[Test]
    public function present_external_key_with_discovery_available_does_not_need_attention(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productVariantBinding('sku');
        $this->publishAuthoritativeSnapshot($account, ['present_key']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'present_key',
        );

        $model = $this->project($account, $configuration);
        $row = $this->rowForBindingId($model, $binding->id);

        $this->assertTrue($model->discoveryAvailable);
        $this->assertSame('present_key', $row->existingExternalFieldKey);
        $this->assertFalse($row->needsAttention);
    }

    #[Test]
    public function active_global_eligible_binding_appears_in_projection(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);

        $model = $this->project($account, $configuration);

        $this->assertNotNull($this->rowForCode($model, 'name', FieldObjectType::Product));
    }

    #[Test]
    public function active_same_workspace_custom_binding_appears_without_canonical_suggestion(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $customBinding = $this->createWorkspaceScopedProductBinding($this->defaultWorkspace());
        $this->publishAuthoritativeSnapshot($account, ['sku']);

        $model = $this->project($account, $configuration);
        $row = $this->rowForBindingId($model, $customBinding->id);

        $this->assertNotNull($row);
        $this->assertNull($row->suggestedExternalFieldKey);
    }

    #[Test]
    public function foreign_workspace_binding_does_not_leak_into_projection(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $foreignBinding = $this->createForeignWorkspaceBinding();

        $model = $this->project($account, $configuration);

        $this->assertFalse(
            collect($model->internalRows)->contains(
                fn ($row) => $row->fieldBindingId === $foreignBinding->id,
            ),
        );
    }

    #[Test]
    public function archived_mapped_binding_remains_present_in_projection(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productVariantBinding('sku');
        $this->publishAuthoritativeSnapshot($account, ['archived_mapped_key']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'archived_mapped_key',
        );

        $binding->update(['status' => AttributeStatus::Archived]);

        $model = $this->project($account, $configuration);
        $row = $this->rowForBindingId($model, $binding->id);

        $this->assertNotNull($row);
        $this->assertSame('archived_mapped_key', $row->existingExternalFieldKey);
        $this->assertTrue($row->needsAttention);
    }

    #[Test]
    public function account_a_cannot_project_account_b_sync_configuration(): void
    {
        $accountA = $this->createSyncSupportAccount(['name' => 'Account A']);
        $accountB = $this->createSyncSupportAccount(['name' => 'Account B']);
        $configurationB = $this->createProductsSyncConfiguration($accountB);

        $this->expectException(SyncConfigurationNotFoundException::class);

        $this->project($accountA, $configurationB);
    }

    #[Test]
    public function ambient_workspace_a_does_not_corrupt_explicit_workspace_b_projection(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Projection workspace B', 'is_default' => false]);

        $accountB = $this->createSyncSupportAccountForWorkspace($workspaceB);
        $configurationB = $this->createProductsSyncConfiguration($accountB);
        $bindingB = $this->createWorkspaceScopedProductBinding($workspaceB);

        $context = app(WorkspaceContext::class);
        $context->reset();
        $this->setWorkspaceContext($workspaceA);

        $model = $this->project($accountB, $configurationB);

        $this->assertNotNull($this->rowForBindingId($model, $bindingB->id));
    }

    private function createSyncSupportAccount(array $overrides = []): ConnectorAccount
    {
        return $this->createConnectorAccount(null, array_merge([
            'auth_profile' => 'test_sync_support',
        ], $overrides));
    }

    private function createSyncSupportAccountForWorkspace(Workspace $workspace): ConnectorAccount
    {
        return $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
    }

    private function project(ConnectorAccount $account, SyncConfiguration $configuration): FieldMappingReadModel
    {
        return app(FieldMappingReadModelProjector::class)->project($account, $configuration->id);
    }

    private function rowForCode(
        FieldMappingReadModel $model,
        string $code,
        FieldObjectType $objectType,
    ): FieldMappingInternalRow {
        foreach ($model->internalRows as $row) {
            if ($row->internalFieldCode === $code && $row->objectType === $objectType) {
                return $row;
            }
        }

        $this->fail(sprintf('Row for code [%s] and object type [%s] not found.', $code, $objectType->value));
    }

    private function rowForBindingId(
        FieldMappingReadModel $model,
        string $bindingId,
    ): FieldMappingInternalRow {
        foreach ($model->internalRows as $row) {
            if ($row->fieldBindingId === $bindingId) {
                return $row;
            }
        }

        $this->fail(sprintf('Row for binding [%s] not found.', $bindingId));
    }

    private function bindCustomRegistry(array $datasets): void
    {
        foreach ($datasets as $filename => $rows) {
            $this->writeCsv($filename, $rows);
        }

        $reader = new CanonicalRegistryReader($this->tempRegistryPath);
        $this->app->instance(CanonicalRegistryReader::class, $reader);
        $this->app->instance(
            CanonicalFieldMappingSuggestionProvider::class,
            new CanonicalFieldMappingSuggestionProvider($reader),
        );
        $this->app->instance(
            FieldMappingReadModelProjector::class,
            new FieldMappingReadModelProjector(
                app(AuthoritativeConnectorSchemaSnapshotResolver::class),
                app(CanonicalFieldMappingSuggestionProvider::class),
            ),
        );
    }

    /**
     * @param  list<array<string, string>>  $fields
     * @param  list<array<string, string>>  $mappings
     * @return array<string, list<array<string, string>>>
     */
    private function minimalRegistryWithMapping(array $fields, array $mappings): array
    {
        return [
            'canonical_product_fields.csv' => $fields,
            'canonical_product_field_mappings.csv' => $mappings,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function fieldRow(
        string $internalCode,
        string $eligibility,
        string $bindingStrategy,
        string $scope,
    ): array {
        return [
            'internal_code' => $internalCode,
            'canonical_english_name' => $internalCode,
            'uk_label' => $internalCode,
            'ru_label' => $internalCode,
            'description' => 'Test field',
            'implementation_kind' => 'core_model_property',
            'storage_owner' => 'Product',
            'field_definition_eligibility' => $eligibility,
            'binding_strategy' => $bindingStrategy,
            'scope' => $scope,
            'field_group_or_state' => 'basic_information',
            'data_type_or_state' => 'text',
            'value_shape' => 'scalar',
            'structure_schema_ref' => 'not_applicable',
            'is_localizable' => 'false',
            'value_localization_strategy' => 'not_localizable',
            'channel_value_strategy' => 'global_value',
            'inheritance_strategy' => 'none',
            'is_multi_value' => 'false',
            'unit_family' => 'not_applicable',
            'status' => 'active',
            'mvp_tier' => 'A',
            'default_enabled' => 'true',
            'verification_status' => 'verified',
            'recommended_action' => 'keep_as_is',
            'supports_admin_display' => 'true',
            'supports_b2b_display' => 'true',
            'supports_search' => 'true',
            'supports_filter' => 'true',
            'supports_table_column' => 'true',
            'evidence_subject_key' => 'field:'.$internalCode,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function mappingRow(string $internalCode, string $channel, string $externalField): array
    {
        return [
            'internal_code' => $internalCode,
            'channel' => $channel,
            'external_field' => $externalField,
            'mapping_type' => 'direct',
            'transformation' => 'not_applicable',
            'applicability_id' => 'a001',
            'requirement_level' => 'required',
            'channel_schema_version' => '2.4.9-admin-rest',
            'verification_status' => 'verified',
            'evidence_subject_key' => 'mapping:'.$channel.':'.$internalCode.':'.$externalField.':a001:2.4.9-admin-rest',
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeCsv(string $filename, array $rows): void
    {
        $path = $this->tempRegistryPath.'/'.$filename;
        $handle = fopen($path, 'w');

        if ($rows === []) {
            fclose($handle);

            return;
        }

        fputcsv($handle, array_keys($rows[0]));

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }

    private function ensurePrimaryAccountDiscoverySource(ConnectorDefinition $definition): void
    {
        $exists = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $definition->id)
            ->where('schema_scope', ConnectorSchemaScope::Account)
            ->where('source_kind', ConnectorSchemaSourceKind::AccountApi)
            ->where('acquisition_mode', ConnectorSchemaAcquisitionMode::LiveFetch)
            ->where('is_primary', true)
            ->exists();

        if ($exists) {
            return;
        }

        ConnectorSchemaSource::query()->create([
            'connector_definition_id' => $definition->id,
            'code' => 'live_account_attributes_test',
            'label' => 'Test live account attributes',
            'source_kind' => ConnectorSchemaSourceKind::AccountApi,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::LiveFetch,
            'schema_scope' => ConnectorSchemaScope::Account,
            'reference_url' => 'https://example.com/schema',
            'endpoint_path' => '/test',
            'schema_version' => '1.0',
            'is_primary' => true,
            'verification_status' => ConnectorSchemaVerificationStatus::Verified,
            'last_verified_at' => now(),
            'sort_order' => 10,
        ]);
    }

    private function setWorkspaceContext(Workspace $workspace): void
    {
        $context = app(WorkspaceContext::class);
        $reflection = new \ReflectionProperty(WorkspaceContext::class, 'current');
        $reflection->setAccessible(true);
        $reflection->setValue($context, $workspace);
    }
}
