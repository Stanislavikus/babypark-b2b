<?php

namespace Tests\Feature\Sync;

use App\Enums\AttributeStatus;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Services\Sync\CreateSyncConfigurationInput;
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
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    public function mapped_field_binding_delete_is_blocked_at_database_and_model_layer(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $this->publishAuthoritativeSnapshot($account, ['name']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'name',
        );

        $this->expectException(FieldBindingReferencedByFieldMappingException::class);
        $binding->delete();

        $this->expectException(QueryException::class);
        DB::table('field_bindings')->where('id', $binding->id)->delete();
    }

    #[Test]
    public function mapped_descendant_blocks_parent_field_definition_delete(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $definition = $binding->fieldDefinition;
        $this->publishAuthoritativeSnapshot($account, ['name']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'name',
        );

        $this->expectException(FieldDefinitionReferencedByFieldMappingException::class);
        $definition->delete();

        $this->expectException(QueryException::class);
        DB::table('field_definitions')->where('id', $definition->id)->delete();
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
    public function customer_binding_and_archived_targets_are_rejected(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $service = app(FieldMappingMutationService::class);
        $this->publishAuthoritativeSnapshot($account, ['customer_key', 'archived_key']);

        $customerBinding = $this->customerBinding();

        $this->expectException(FieldMappingValidationException::class);
        $service->confirm($account, $configuration->id, $customerBinding->id, 'customer_key');

        $archivedBinding = $this->productBinding('description');
        $archivedBinding->update(['status' => AttributeStatus::Archived]);

        $this->expectException(FieldMappingValidationException::class);
        $service->confirm($account, $configuration->id, $archivedBinding->id, 'archived_key');
    }

    #[Test]
    public function discovery_validation_accepts_valid_key_and_rejects_absent_or_missing_snapshot(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $service = app(FieldMappingMutationService::class);

        $this->expectException(AuthoritativeDiscoveryValidationException::class);
        $service->confirm($account, $configuration->id, $binding->id, 'missing_snapshot_key');

        $this->publishAuthoritativeSnapshot($account, ['valid_key']);

        $this->expectException(AuthoritativeDiscoveryValidationException::class);
        $service->confirm($account, $configuration->id, $binding->id, 'absent_key');

        $service->confirm($account, $configuration->id, $binding->id, 'valid_key');
        $this->assertTrue(FieldMapping::withoutWorkspaceScope()->where('external_field_key', 'valid_key')->exists());
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

        $this->expectException(AuthoritativeDiscoveryValidationException::class);
        $service->confirm($account, $configuration->id, $this->productVariantBinding('sku')->id, 'stale_key');

        $this->assertSame($newerSnapshot->id, $newerSnapshot->refresh()->id);
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
    public function cardinality_conflicts_are_detected_for_internal_and_external_duplicates(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $service = app(FieldMappingMutationService::class);
        $bindingA = $this->productBinding('name');
        $bindingB = $this->productBinding('description');

        $this->publishAuthoritativeSnapshot($account, ['key_a', 'key_b']);

        $service->confirm($account, $configuration->id, $bindingA->id, 'key_a');

        $this->expectException(FieldMappingConflictException::class);
        $service->confirm($account, $configuration->id, $bindingA->id, 'key_b');

        $this->expectException(FieldMappingConflictException::class);
        $service->confirm($account, $configuration->id, $bindingB->id, 'key_a');
    }

    #[Test]
    public function same_binding_and_key_allowed_in_another_configuration(): void
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
    public function revision_v2_is_deterministic_and_reflects_mapping_mutations(): void
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

        $afterRemove = $service->remove($account, $configuration->id, $variantBinding->id);
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
}
