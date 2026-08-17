<?php

namespace Tests\Feature\Sync;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Models\Workspace;
use App\Services\Connectors\AuthoritativeConnectorSchemaSnapshotResolver;
use App\Services\Sync\CreateSyncConfigurationInput;
use App\Services\Sync\FieldMappingBindingValidator;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\SyncConfigurationMutationCoordinator;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\UpdateSyncConfigurationInput;
use App\Support\Sync\Exceptions\AuthoritativeDiscoveryValidationException;
use App\Support\Sync\Exceptions\FieldBindingReferencedByFieldMappingException;
use App\Support\Sync\Exceptions\FieldDefinitionReferencedByFieldMappingException;
use App\Support\Sync\Exceptions\FieldMappingConflictException;
use App\Support\Sync\Exceptions\FieldMappingValidationException;
use App\Support\Sync\FieldMappingRevisionEntry;
use App\Support\Sync\SyncConfigurationRevisionHasher;
use App\Support\Sync\SyncExternalContext;
use App\Support\Sync\SyncOperationSet;
use App\Support\Workspace\WorkspaceContext;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

class FieldMappingPersistenceTest extends TestCase
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
        $this->seedFieldDefinitions();
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import],
            [SyncDataDomain::Products, SyncSemanticOperation::Export],
        ]);
    }

    #[Test]
    public function migration_creates_field_mappings_table_with_expected_constraints(): void
    {
        $this->assertTrue(Schema::hasTable('field_mappings'));
        $this->assertTrue(Schema::hasColumn('field_mappings', 'workspace_id'));
        $this->assertTrue(Schema::hasColumn('field_mappings', 'sync_configuration_id'));
        $this->assertTrue(Schema::hasColumn('field_mappings', 'field_binding_id'));
        $this->assertTrue(Schema::hasColumn('field_mappings', 'external_field_key'));
        $this->assertTrue($this->indexExists('field_mappings', 'fm_config_binding_unique'));
        $this->assertTrue($this->indexExists('field_mappings', 'fm_config_external_key_unique'));
    }

    #[Test]
    public function migration_rolls_back_and_remigrates_cleanly(): void
    {
        $this->rollbackThrough('2026_08_12_110000_field_mappings');

        $this->assertFalse(Schema::hasTable('field_mappings'));

        $this->artisan('migrate')->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('field_mappings'));
    }

    #[Test]
    public function parent_sync_configuration_delete_cascades_mappings(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $this->publishAuthoritativeSnapshot($account, ['sku']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'sku',
        );

        $this->assertSame(1, FieldMapping::withoutWorkspaceScope()->count());

        SyncConfiguration::withoutWorkspaceScope()->whereKey($configuration->id)->delete();

        $this->assertSame(0, FieldMapping::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function mapped_field_binding_model_delete_raises_domain_exception(): void
    {
        $fixture = $this->createMappedFieldBindingFixture('model_delete_key');

        $this->expectException(FieldBindingReferencedByFieldMappingException::class);
        $fixture['binding']->delete();
    }

    #[Test]
    public function mapped_field_binding_raw_delete_is_restricted_by_database(): void
    {
        $fixture = $this->createMappedFieldBindingFixture('raw_delete_key');

        $this->expectException(QueryException::class);
        DB::table('field_bindings')->where('id', $fixture['binding']->id)->delete();
    }

    #[Test]
    public function mapped_field_definition_model_delete_raises_domain_exception(): void
    {
        $fixture = $this->createMappedFieldBindingFixture('definition_model_delete_key');
        $definition = $fixture['binding']->fieldDefinition;

        $this->expectException(FieldDefinitionReferencedByFieldMappingException::class);
        $definition->delete();
    }

    #[Test]
    public function mapped_field_definition_raw_delete_is_restricted_transitively(): void
    {
        $fixture = $this->createMappedFieldBindingFixture('definition_raw_delete_key');
        $definition = $fixture['binding']->fieldDefinition;

        $this->expectException(QueryException::class);
        DB::table('field_definitions')->where('id', $definition->id)->delete();
    }

    #[Test]
    public function cross_workspace_field_definition_model_delete_raises_domain_exception(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Cross-workspace B', 'is_default' => false]);

        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => null,
            'code' => 'global_cross_ws_'.Str::random(6),
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::PlatformLibrary,
            'localized_labels' => ['uk' => 'Глобальне поле'],
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        $bindingB = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceB->id,
            'field_definition_id' => $definition->id,
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
        $configuration = $this->createProductsSyncConfiguration($accountB);

        $context = app(WorkspaceContext::class);
        $context->reset();
        $this->setWorkspaceContext($workspaceB);

        $this->publishAuthoritativeSnapshot($accountB, ['cross_ws_key']);

        app(FieldMappingMutationService::class)->confirm(
            $accountB,
            $configuration->id,
            $bindingB->id,
            'cross_ws_key',
        );

        $this->setWorkspaceContext($workspaceA);

        $this->assertFalse(
            $definition->fieldBindings()->whereKey($bindingB->id)->exists(),
            'Workspace B binding must be hidden from workspace A scoped reads.',
        );

        $this->expectException(FieldDefinitionReferencedByFieldMappingException::class);
        $definition->delete();
    }

    #[Test]
    public function cross_workspace_field_definition_raw_delete_is_restricted_transitively(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Cross-workspace B raw', 'is_default' => false]);

        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => null,
            'code' => 'global_cross_ws_raw_'.Str::random(6),
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::PlatformLibrary,
            'localized_labels' => ['uk' => 'Глобальне поле'],
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        $bindingB = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceB->id,
            'field_definition_id' => $definition->id,
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
        $configuration = $this->createProductsSyncConfiguration($accountB);

        $this->setWorkspaceContext($workspaceB);
        $this->publishAuthoritativeSnapshot($accountB, ['cross_ws_raw_key']);

        app(FieldMappingMutationService::class)->confirm(
            $accountB,
            $configuration->id,
            $bindingB->id,
            'cross_ws_raw_key',
        );

        $this->setWorkspaceContext($workspaceA);

        $this->expectException(QueryException::class);
        DB::table('field_definitions')->where('id', $definition->id)->delete();
    }

    #[Test]
    public function confirm_rechecks_exact_pair_inside_locked_mutation_coordinator(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $this->publishAuthoritativeSnapshot($account, ['reconfirm_key']);

        $service = app(FieldMappingMutationService::class);
        $service->confirm($account, $configuration->id, $binding->id, 'reconfirm_key');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $beforeRevision = SyncConfiguration::withoutWorkspaceScope()
            ->findOrFail($configuration->id)
            ->configuration_revision;

        $result = app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'reconfirm_key',
        );

        $queries = collect(DB::getQueryLog())->pluck('query');
        $this->assertTrue(
            $queries->contains(fn (string $query): bool => str_contains(strtolower($query), 'sync_configurations')),
            'Reconfirm must enter the locked parent mutation path instead of an unlocked early return.',
        );
        $this->assertSame($beforeRevision, $result->configuration_revision);
        $this->assertSame(1, FieldMapping::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function duplicate_confirms_converge_without_false_conflicts(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $this->publishAuthoritativeSnapshot($account, ['converge_key']);
        $service = app(FieldMappingMutationService::class);

        $first = $service->confirm($account, $configuration->id, $binding->id, 'converge_key');
        $second = $service->confirm($account, $configuration->id, $binding->id, 'converge_key');

        $this->assertSame(1, FieldMapping::withoutWorkspaceScope()->count());
        $this->assertSame($first->configuration_revision, $second->configuration_revision);
    }

    #[Test]
    public function migration_revision_roundtrip_restores_v1_and_reapplies_v2(): void
    {
        $this->rollbackThrough('2026_08_12_110000_field_mappings');

        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);

        $cases = [
            [['import'], SyncConfigurationOperationalState::Enabled->value],
            [['export'], SyncConfigurationOperationalState::Enabled->value],
            [['export', 'import'], SyncConfigurationOperationalState::Enabled->value],
            [['import'], SyncConfigurationOperationalState::Paused->value],
        ];

        foreach ($cases as [$operations, $state]) {
            $v1Revision = $this->migrationHashV1($operations, $state);
            $expectedV2 = $this->migrationHashV2($operations, $state);

            DB::table('sync_configurations')->where('id', $configuration->id)->update([
                'enabled_operations' => json_encode($operations, JSON_THROW_ON_ERROR),
                'operational_state' => $state,
                'configuration_revision' => $v1Revision,
            ]);

            $migration = $this->fieldMappingsMigration();
            $migration->up();

            $this->assertSame(
                $expectedV2,
                DB::table('sync_configurations')->where('id', $configuration->id)->value('configuration_revision'),
            );

            $migration->down();

            $this->assertSame(
                $v1Revision,
                DB::table('sync_configurations')->where('id', $configuration->id)->value('configuration_revision'),
            );

            $migration->up();

            $this->assertSame(
                $expectedV2,
                DB::table('sync_configurations')->where('id', $configuration->id)->value('configuration_revision'),
            );

            $migration->down();
        }

        $this->artisan('migrate')->assertExitCode(0);
    }

    #[Test]
    public function migration_v4_rebaseline_matches_runtime_hasher_for_empty_mappings(): void
    {
        $migration = require database_path('migrations/2026_08_17_120000_sync_configuration_revision_v4.php');
        $reflection = new \ReflectionClass($migration);
        $hashMethod = $reflection->getMethod('hashRevisionV4');
        $hashMethod->setAccessible(true);
        $canonicalMethod = $reflection->getMethod('canonicalizePersistedOperations');
        $canonicalMethod->setAccessible(true);

        $hasher = new SyncConfigurationRevisionHasher;
        $cases = [
            [['import'], SyncConfigurationOperationalState::Enabled],
            [['export', 'import'], SyncConfigurationOperationalState::Paused],
            [['import', 'import'], SyncConfigurationOperationalState::Enabled],
            [['export', 'import', 'export'], SyncConfigurationOperationalState::Enabled],
        ];

        foreach ($cases as [$operations, $state]) {
            $canonical = $canonicalMethod->invoke($migration, $operations);
            $migrationHash = $hashMethod->invoke($migration, $canonical, $state->value, [], []);
            $runtimeHash = $hasher->hash(
                SyncOperationSet::fromOperations(
                    array_map(
                        static fn (string $operation): SyncSemanticOperation => SyncSemanticOperation::from($operation),
                        $canonical,
                    ),
                ),
                $state,
                [],
            );

            $this->assertSame(
                $runtimeHash,
                $migrationHash,
                'Revision mismatch for operations ['.implode(',', $operations).'] state '.$state->value,
            );
        }
    }

    #[Test]
    public function same_workspace_and_global_bindings_are_accepted(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $service = app(FieldMappingMutationService::class);

        $this->publishAuthoritativeSnapshot($account, ['name', 'sku', 'ws_key']);

        $globalBinding = $this->productBinding('name');
        $variantBinding = $this->productVariantBinding('sku');
        $workspaceBinding = $this->createWorkspaceScopedProductBinding($this->defaultWorkspace());

        $service->confirm($account, $configuration->id, $globalBinding->id, 'name');
        $service->confirm($account, $configuration->id, $variantBinding->id, 'sku');
        $service->confirm($account, $configuration->id, $workspaceBinding->id, 'ws_key');

        $this->assertSame(3, FieldMapping::withoutWorkspaceScope()->where('sync_configuration_id', $configuration->id)->count());
    }

    #[Test]
    public function foreign_workspace_binding_is_rejected(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $foreignBinding = $this->createForeignWorkspaceBinding();
        $this->publishAuthoritativeSnapshot($account, ['foreign_key']);

        $this->expectException(FieldMappingValidationException::class);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $foreignBinding->id,
            'foreign_key',
        );
    }

    #[Test]
    public function customer_binding_is_rejected_for_products_mapping(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['customer_key']);

        $this->expectException(FieldMappingValidationException::class);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->customerBinding()->id,
            'customer_key',
        );
    }

    #[Test]
    public function archived_binding_is_rejected_for_products_mapping(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $this->publishAuthoritativeSnapshot($account, ['archived_binding_key']);

        $archivedBinding = $this->productBinding('description');
        $archivedBinding->update(['status' => AttributeStatus::Archived]);

        $this->expectException(FieldMappingValidationException::class);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $archivedBinding->id,
            'archived_binding_key',
        );
    }

    #[Test]
    public function archived_definition_is_rejected_for_products_mapping(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding('description');
        $binding->fieldDefinition->update(['status' => AttributeStatus::Archived]);
        $this->publishAuthoritativeSnapshot($account, ['archived_definition_key']);

        $this->expectException(FieldMappingValidationException::class);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'archived_definition_key',
        );
    }

    #[Test]
    public function discovery_validation_rejects_when_no_authoritative_snapshot(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();

        $this->expectException(AuthoritativeDiscoveryValidationException::class);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'missing_snapshot_key',
        );
    }

    #[Test]
    public function discovery_validation_rejects_absent_external_field_key(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $this->publishAuthoritativeSnapshot($account, ['present_key']);

        $this->expectException(AuthoritativeDiscoveryValidationException::class);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'absent_key',
        );
    }

    #[Test]
    public function discovery_validation_accepts_valid_external_field_key(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $this->publishAuthoritativeSnapshot($account, ['valid_key']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'valid_key',
        );

        $this->assertTrue(FieldMapping::withoutWorkspaceScope()->where('external_field_key', 'valid_key')->exists());
    }

    #[Test]
    public function external_field_key_lookup_is_bound_to_supplied_snapshot_id(): void
    {
        $account = $this->createSyncSupportAccount();
        $snapshotS1 = $this->publishAuthoritativeSnapshot($account, ['key_a'], now()->subHour());
        $snapshotS2 = $this->publishAuthoritativeSnapshot($account, ['other_key'], now());

        $resolver = app(AuthoritativeConnectorSchemaSnapshotResolver::class);

        $this->assertTrue($resolver->externalFieldKeyExists($snapshotS1, 'key_a'));
        $this->assertFalse($resolver->externalFieldKeyExists($snapshotS2, 'key_a'));
    }

    #[Test]
    public function authoritative_discovery_validation_resolves_discovery_source_and_snapshot_once(): void
    {
        $account = $this->createSyncSupportAccount();
        $this->publishAuthoritativeSnapshot($account, ['once_key']);

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

        app(FieldMappingBindingValidator::class)->assertExternalFieldKeyInAuthoritativeSnapshot(
            $account,
            'once_key',
        );

        $this->assertSame(1, $sourceQueries);
        $this->assertSame(1, $snapshotQueries);
    }

    #[Test]
    public function workspace_b_definition_accepted_with_ambient_workspace_a_context(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Validator workspace B', 'is_default' => false]);

        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceB->id,
            'code' => 'ws_b_def_'.Str::random(6),
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Поле B'],
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        $bindingB = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceB->id,
            'field_definition_id' => $definition->id,
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
        $configuration = $this->createProductsSyncConfiguration($accountB);

        $this->setWorkspaceContext($workspaceA);
        $this->setWorkspaceContext($workspaceB);
        $this->publishAuthoritativeSnapshot($accountB, ['ws_b_key']);
        $this->setWorkspaceContext($workspaceA);

        app(FieldMappingMutationService::class)->confirm(
            $accountB,
            $configuration->id,
            $bindingB->id,
            'ws_b_key',
        );

        $this->assertTrue(
            FieldMapping::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configuration->id)
                ->where('field_binding_id', $bindingB->id)
                ->exists(),
        );
    }

    #[Test]
    public function global_binding_with_foreign_workspace_definition_is_rejected(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Validator foreign B', 'is_default' => false]);

        $foreignDefinition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceB->id,
            'code' => 'ws_b_foreign_'.Str::random(6),
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Поле B foreign'],
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        $globalBinding = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => null,
            'field_definition_id' => $foreignDefinition->id,
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

        $accountA = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($accountA);
        $this->publishAuthoritativeSnapshot($accountA, ['foreign_def_key']);

        $this->setWorkspaceContext($workspaceA);

        $this->expectException(FieldMappingValidationException::class);

        app(FieldMappingMutationService::class)->confirm(
            $accountA,
            $configuration->id,
            $globalBinding->id,
            'foreign_def_key',
        );
    }

    #[Test]
    public function duplicate_internal_target_in_same_configuration_is_rejected(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding('name');
        $this->publishAuthoritativeSnapshot($account, ['key_a', 'key_b']);

        app(FieldMappingMutationService::class)->confirm($account, $configuration->id, $binding->id, 'key_a');

        $this->expectException(FieldMappingConflictException::class);

        app(FieldMappingMutationService::class)->confirm($account, $configuration->id, $binding->id, 'key_b');
    }

    #[Test]
    public function duplicate_external_key_in_same_configuration_is_rejected(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $bindingA = $this->productBinding('name');
        $bindingB = $this->productBinding('description');
        $this->publishAuthoritativeSnapshot($account, ['shared_key']);

        app(FieldMappingMutationService::class)->confirm($account, $configuration->id, $bindingA->id, 'shared_key');

        $this->expectException(FieldMappingConflictException::class);

        app(FieldMappingMutationService::class)->confirm($account, $configuration->id, $bindingB->id, 'shared_key');
    }

    #[Test]
    public function deterministic_latest_snapshot_uses_created_at_and_id_ordering(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $service = app(FieldMappingMutationService::class);

        $olderTime = now()->subHour();
        $this->publishAuthoritativeSnapshot($account, ['stale_key'], $olderTime);

        $newerSnapshot = $this->publishAuthoritativeSnapshot($account, ['fresh_key'], now());

        $service->confirm($account, $configuration->id, $binding->id, 'fresh_key');
        $this->assertTrue(FieldMapping::withoutWorkspaceScope()->where('external_field_key', 'fresh_key')->exists());
        $this->assertSame($newerSnapshot->id, $newerSnapshot->refresh()->id);
    }

    #[Test]
    public function stale_external_key_from_older_snapshot_is_rejected(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $service = app(FieldMappingMutationService::class);

        $this->publishAuthoritativeSnapshot($account, ['stale_key'], now()->subHour());
        $this->publishAuthoritativeSnapshot($account, ['fresh_key'], now());

        $this->expectException(AuthoritativeDiscoveryValidationException::class);

        $service->confirm(
            $account,
            $configuration->id,
            $this->productVariantBinding('sku')->id,
            'stale_key',
        );
    }

    #[Test]
    public function new_snapshot_without_key_retains_persisted_mapping(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $service = app(FieldMappingMutationService::class);

        $this->publishAuthoritativeSnapshot($account, ['retained_key']);
        $service->confirm($account, $configuration->id, $binding->id, 'retained_key');

        $revisionAfterConfirm = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;

        $this->publishAuthoritativeSnapshot($account, ['other_key']);

        $this->assertTrue(FieldMapping::withoutWorkspaceScope()->where('external_field_key', 'retained_key')->exists());
        $this->assertSame(
            $revisionAfterConfirm,
            SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision,
        );
    }

    #[Test]
    public function same_external_key_is_allowed_across_different_configurations(): void
    {
        $account = $this->createSyncSupportAccount();
        $configurationA = $this->createProductsSyncConfiguration($account);
        $configurationB = app(SyncConfigurationService::class)->create(
            $account,
            new CreateSyncConfigurationInput(
                dataDomain: SyncDataDomain::Products,
                externalContext: SyncExternalContext::fromPayload(['region' => 'eu']),
                enabledOperations: [SyncSemanticOperation::Import],
            ),
        );

        $binding = $this->productBinding();
        $this->publishAuthoritativeSnapshot($account, ['shared_key']);
        $service = app(FieldMappingMutationService::class);

        $service->confirm($account, $configurationA->id, $binding->id, 'shared_key');
        $service->confirm($account, $configurationB->id, $binding->id, 'shared_key');

        $this->assertSame(2, FieldMapping::withoutWorkspaceScope()->where('external_field_key', 'shared_key')->count());
    }

    #[Test]
    public function revision_v3_is_deterministic_and_reflects_mapping_mutations(): void
    {
        $hasher = new SyncConfigurationRevisionHasher;
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $service = app(FieldMappingMutationService::class);
        $binding = $this->productBinding();
        $variantBinding = $this->productVariantBinding();

        $this->publishAuthoritativeSnapshot($account, ['name', 'sku']);

        $baseRevision = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;

        $afterConfirm = $service->confirm($account, $configuration->id, $binding->id, 'name');
        $this->assertNotSame($baseRevision, $afterConfirm->configuration_revision);

        $reconfirm = $service->confirm($account, $configuration->id, $binding->id, 'name');
        $this->assertSame($afterConfirm->configuration_revision, $reconfirm->configuration_revision);

        $unorderedHash = $hasher->hash(
            $configuration->enabledOperationSet(),
            SyncConfigurationOperationalState::Enabled,
            [
                new FieldMappingRevisionEntry($variantBinding->id, 'sku'),
                new FieldMappingRevisionEntry($binding->id, 'name'),
            ],
        );

        $orderedHash = $hasher->hash(
            $configuration->enabledOperationSet(),
            SyncConfigurationOperationalState::Enabled,
            [
                new FieldMappingRevisionEntry($binding->id, 'name'),
                new FieldMappingRevisionEntry($variantBinding->id, 'sku'),
            ],
        );

        $this->assertSame($unorderedHash, $orderedHash);

        $afterReplace = $service->replace(
            $account,
            $configuration->id,
            $binding->id,
            'name',
            newExternalFieldKey: 'sku',
            newFieldBindingId: $variantBinding->id,
        );
        $this->assertNotSame($afterConfirm->configuration_revision, $afterReplace->configuration_revision);

        $afterRemove = $service->remove($account, $configuration->id, $variantBinding->id, 'sku');
        $this->assertNotSame($afterReplace->configuration_revision, $afterRemove->configuration_revision);
    }

    #[Test]
    public function configuration_operation_update_preserves_mappings_in_revision(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $this->publishAuthoritativeSnapshot($account, ['name']);

        app(FieldMappingMutationService::class)->confirm($account, $configuration->id, $binding->id, 'name');
        $revisionWithMapping = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;

        $updated = app(SyncConfigurationService::class)->update(
            $account,
            $configuration->id,
            new UpdateSyncConfigurationInput(
                operationalState: SyncConfigurationOperationalState::Paused,
            ),
        );

        $this->assertNotSame($revisionWithMapping, $updated->configuration_revision);
        $this->assertTrue(FieldMapping::withoutWorkspaceScope()->where('sync_configuration_id', $configuration->id)->exists());

        $expected = (new SyncConfigurationRevisionHasher)->hash(
            $updated->enabledOperationSet(),
            SyncConfigurationOperationalState::Paused,
            [new FieldMappingRevisionEntry($binding->id, 'name')],
        );
        $this->assertSame($expected, $updated->configuration_revision);
    }

    #[Test]
    public function sync_configuration_update_uses_row_lock_protocol(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);

        app(SyncConfigurationService::class)->update(
            $account,
            $configuration->id,
            new UpdateSyncConfigurationInput(
                operationalState: SyncConfigurationOperationalState::Paused,
            ),
        );

        $coordinator = app(SyncConfigurationMutationCoordinator::class);
        DB::transaction(function () use ($coordinator, $account, $configuration): void {
            $locked = $coordinator->lockConfiguration($account, $configuration->id);
            $this->assertSame($configuration->id, $locked->id);
        });

        $mappingService = app(FieldMappingMutationService::class);
        $binding = $this->productBinding();
        $this->publishAuthoritativeSnapshot($account, ['lock_key']);

        $mappingService->confirm($account, $configuration->id, $binding->id, 'lock_key');
        $this->assertTrue(FieldMapping::withoutWorkspaceScope()->where('external_field_key', 'lock_key')->exists());
    }

    #[Test]
    public function invalid_binding_rolls_back_mapping_mutation_atomically(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $foreignBinding = $this->createForeignWorkspaceBinding();
        $this->publishAuthoritativeSnapshot($account, ['atomic_key']);

        try {
            app(FieldMappingMutationService::class)->confirm(
                $account,
                $configuration->id,
                $foreignBinding->id,
                'atomic_key',
            );
            $this->fail('Expected FieldMappingValidationException');
        } catch (FieldMappingValidationException) {
            // expected
        }

        $this->assertSame(0, FieldMapping::withoutWorkspaceScope()->count());
        $this->assertSame(
            $configuration->configuration_revision,
            SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision,
        );
    }

    private function createSyncSupportAccount(): ConnectorAccount
    {
        return $this->createConnectorAccount(null, ['auth_profile' => 'test_sync_support']);
    }

    private function createSyncSupportAccountForWorkspace(Workspace $workspace): ConnectorAccount
    {
        return $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
    }

    private function rollbackThrough(string $targetMigration): void
    {
        $migrations = DB::table('migrations')
            ->orderByDesc('batch')
            ->orderByDesc('migration')
            ->pluck('migration')
            ->values();

        $position = $migrations->search($targetMigration);

        $this->assertNotSame(false, $position, "Target migration is not recorded as applied: {$targetMigration}");

        $this->artisan('migrate:rollback', [
            '--step' => ((int) $position) + 1,
        ])->assertExitCode(0);
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select("PRAGMA index_list('{$table}')");

            foreach ($rows as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            $database = $connection->getDatabaseName();
            $rows = $connection->select(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$database, $table, $index],
            );

            return $rows !== [];
        }

        return false;
    }

    /**
     * @return array{account: ConnectorAccount, configuration: SyncConfiguration, binding: FieldBinding}
     */
    private function createMappedFieldBindingFixture(string $externalKey): array
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $this->publishAuthoritativeSnapshot($account, [$externalKey]);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            $externalKey,
        );

        return [
            'account' => $account,
            'configuration' => $configuration,
            'binding' => $binding,
        ];
    }

    private function setWorkspaceContext(Workspace $workspace): void
    {
        $context = app(WorkspaceContext::class);
        $reflection = new \ReflectionProperty(WorkspaceContext::class, 'current');
        $reflection->setAccessible(true);
        $reflection->setValue($context, $workspace);
    }

    /**
     * @param  list<string>  $operations
     */
    private function migrationHashV1(array $operations, string $operationalState): string
    {
        $migration = $this->fieldMappingsMigration();
        $reflection = new \ReflectionClass($migration);
        $canonicalMethod = $reflection->getMethod('canonicalizePersistedOperations');
        $canonicalMethod->setAccessible(true);
        $hashMethod = $reflection->getMethod('hashRevisionV1');
        $hashMethod->setAccessible(true);

        $canonical = $canonicalMethod->invoke($migration, $operations);

        return $hashMethod->invoke($migration, $canonical, $operationalState);
    }

    /**
     * @param  list<string>  $operations
     */
    private function migrationHashV2(array $operations, string $operationalState): string
    {
        $migration = $this->fieldMappingsMigration();
        $reflection = new \ReflectionClass($migration);
        $canonicalMethod = $reflection->getMethod('canonicalizePersistedOperations');
        $canonicalMethod->setAccessible(true);
        $hashMethod = $reflection->getMethod('hashRevisionV2EmptyMappings');
        $hashMethod->setAccessible(true);

        $canonical = $canonicalMethod->invoke($migration, $operations);

        return $hashMethod->invoke($migration, $canonical, $operationalState);
    }

    private function fieldMappingsMigration(): object
    {
        return require database_path('migrations/2026_08_12_110000_field_mappings.php');
    }
}
