<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncPreviewOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Jobs\Connectors\SyncPreviewRunJob;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\FieldMapping;
use App\Models\Product;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncPreviewAdmissionService;
use App\Services\Sync\UpdateSyncConfigurationInput;
use App\Support\Connectors\AdobePaaS\AdobeProductExportPreviewPlanner;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use App\Support\Sync\FieldMappingRevisionEntry;
use App\Support\Sync\SyncConfigurationRevisionHasher;
use App\Support\Sync\SyncOperationSet;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
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

class SyncRunPersistenceFoundationTest extends TestCase
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
    public function revision_hasher_uses_v4_prefix(): void
    {
        $hasher = new SyncConfigurationRevisionHasher;
        $revision = $hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Import]),
            SyncConfigurationOperationalState::Enabled,
            [],
        );

        $migration = $this->revisionV4Migration();
        $reflection = new \ReflectionClass($migration);
        $hashMethod = $reflection->getMethod('hashRevisionV4');
        $hashMethod->setAccessible(true);
        $canonicalMethod = $reflection->getMethod('canonicalizePersistedOperations');
        $canonicalMethod->setAccessible(true);

        $expected = $hashMethod->invoke(
            $migration,
            $canonicalMethod->invoke($migration, ['import']),
            SyncConfigurationOperationalState::Enabled->value,
            [],
            [],
        );

        $this->assertSame($expected, $revision);
        $this->assertNotSame(
            $this->migrationHashV3(['import'], SyncConfigurationOperationalState::Enabled->value, []),
            $revision,
        );
    }

    #[Test]
    public function fixed_selection_all_products_changes_v2_to_v3_revision(): void
    {
        $hasher = new SyncConfigurationRevisionHasher;
        $operations = SyncOperationSet::fromOperations([SyncSemanticOperation::Export]);
        $state = SyncConfigurationOperationalState::Enabled;
        $mappings = [new FieldMappingRevisionEntry($this->productBinding()->id, 'sku')];

        $v3 = $hasher->hash($operations, $state, $mappings);
        $v2 = $this->migrationHashV2(['export'], $state->value, [
            ['field_binding_id' => $this->productBinding()->id, 'external_field_key' => 'sku'],
        ]);

        $this->assertNotSame($v2, $v3);
    }

    #[Test]
    public function revision_v3_hashes_full_enabled_operation_set(): void
    {
        $hasher = new SyncConfigurationRevisionHasher;

        $importExport = $hasher->hash(
            SyncOperationSet::fromOperations([
                SyncSemanticOperation::Import,
                SyncSemanticOperation::Export,
            ]),
            SyncConfigurationOperationalState::Enabled,
            [],
        );

        $exportOnly = $hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Export]),
            SyncConfigurationOperationalState::Enabled,
            [],
        );

        $this->assertNotSame($importExport, $exportOnly);
    }

    #[Test]
    public function revision_v3_operation_order_and_deduplication_are_canonical(): void
    {
        $hasher = new SyncConfigurationRevisionHasher;
        $state = SyncConfigurationOperationalState::Enabled;

        $first = $hasher->hash(
            SyncOperationSet::fromOperations([
                SyncSemanticOperation::Import,
                SyncSemanticOperation::Export,
            ]),
            $state,
            [],
        );

        $second = $hasher->hash(
            SyncOperationSet::fromOperations([
                SyncSemanticOperation::Export,
                SyncSemanticOperation::Import,
                SyncSemanticOperation::Import,
            ]),
            $state,
            [],
        );

        $this->assertSame($first, $second);
    }

    #[Test]
    public function revision_v3_mapping_order_is_canonical(): void
    {
        $hasher = new SyncConfigurationRevisionHasher;
        $operations = SyncOperationSet::fromOperations([SyncSemanticOperation::Import]);
        $state = SyncConfigurationOperationalState::Enabled;
        $bindingA = $this->productBinding('name');
        $bindingB = $this->productVariantBinding('sku');

        $unordered = $hasher->hash($operations, $state, [
            new FieldMappingRevisionEntry($bindingB->id, 'sku'),
            new FieldMappingRevisionEntry($bindingA->id, 'name'),
        ]);

        $ordered = $hasher->hash($operations, $state, [
            new FieldMappingRevisionEntry($bindingA->id, 'name'),
            new FieldMappingRevisionEntry($bindingB->id, 'sku'),
        ]);

        $this->assertSame($unordered, $ordered);
    }

    #[Test]
    public function migration_v4_matches_runtime_hasher_for_non_canonical_mapping_insert_order(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $bindingA = $this->productBinding('name');
        $bindingB = $this->productVariantBinding('sku');
        $this->publishAuthoritativeSnapshot($account, ['name', 'sku']);

        DB::table('field_mappings')->insert([
            [
                'id' => (string) Str::uuid(),
                'workspace_id' => $account->workspace_id,
                'sync_configuration_id' => $configuration->id,
                'field_binding_id' => $bindingB->id,
                'external_field_key' => 'sku',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'workspace_id' => $account->workspace_id,
                'sync_configuration_id' => $configuration->id,
                'field_binding_id' => $bindingA->id,
                'external_field_key' => 'name',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $migration = $this->revisionV4Migration();
        $reflection = new \ReflectionClass($migration);
        $hashMethod = $reflection->getMethod('hashRevisionV4');
        $hashMethod->setAccessible(true);
        $canonicalMethod = $reflection->getMethod('canonicalizePersistedOperations');
        $canonicalMethod->setAccessible(true);
        $mappingMethod = $reflection->getMethod('canonicalFieldMappingsForConfiguration');
        $mappingMethod->setAccessible(true);

        $migrationHash = $hashMethod->invoke(
            $migration,
            $canonicalMethod->invoke($migration, ['import']),
            SyncConfigurationOperationalState::Enabled->value,
            $mappingMethod->invoke($migration, $configuration->id),
            [],
        );

        $runtimeHash = (new SyncConfigurationRevisionHasher)->hash(
            $configuration->enabledOperationSet(),
            SyncConfigurationOperationalState::Enabled,
            [
                new FieldMappingRevisionEntry($bindingA->id, 'name'),
                new FieldMappingRevisionEntry($bindingB->id, 'sku'),
            ],
        );

        $this->assertSame($runtimeHash, $migrationHash);
    }

    #[Test]
    public function migration_v3_roundtrip_restores_v2_with_current_mappings(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();

        DB::table('field_mappings')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'field_binding_id' => $binding->id,
            'external_field_key' => 'roundtrip_key',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $expectedV2 = $this->migrationHashV2(
            ['import'],
            SyncConfigurationOperationalState::Enabled->value,
            [['field_binding_id' => $binding->id, 'external_field_key' => 'roundtrip_key']],
        );

        $this->rollbackThrough('2026_08_16_100000_sync_configuration_revision_v3');

        $v2Before = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;
        $this->assertSame($expectedV2, $v2Before);

        $migration = $this->revisionV3Migration();
        $migration->up();

        $v3After = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;
        $this->assertNotSame($expectedV2, $v3After);

        $migration->down();

        $v2After = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;
        $this->assertSame($expectedV2, $v2After);
        $this->assertTrue(FieldMapping::withoutWorkspaceScope()->where('sync_configuration_id', $configuration->id)->exists());

        $this->artisan('migrate')->assertExitCode(0);
    }

    #[Test]
    public function sync_configuration_mutation_path_writes_v3_revision(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);

        $updated = app(SyncConfigurationService::class)->update(
            $account,
            $configuration->id,
            new UpdateSyncConfigurationInput(
                operationalState: SyncConfigurationOperationalState::Paused,
            ),
        );

        $expected = (new SyncConfigurationRevisionHasher)->hash(
            $updated->enabledOperationSet(),
            SyncConfigurationOperationalState::Paused,
            [],
        );

        $this->assertSame($expected, $updated->configuration_revision);
    }

    #[Test]
    public function field_mapping_mutation_path_writes_v3_revision(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $this->publishAuthoritativeSnapshot($account, ['mutation_key']);

        $result = app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'mutation_key',
        );

        $expected = (new SyncConfigurationRevisionHasher)->hash(
            $configuration->enabledOperationSet(),
            SyncConfigurationOperationalState::Enabled,
            [new FieldMappingRevisionEntry($binding->id, 'mutation_key')],
        );

        $this->assertSame($expected, $result->configuration_revision);
    }

    #[Test]
    public function migration_creates_sync_runs_table_with_expected_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('sync_runs'));
        $this->assertTrue(Schema::hasColumn('sync_runs', 'configuration_snapshot'));
        $this->assertTrue(Schema::hasColumn('sync_runs', 'initiated_by_user_id'));
        $this->assertTrue($this->indexExists('sync_runs', 'sr_ws_id_unique'));
        $this->assertTrue($this->indexExists('sync_runs', 'sr_ws_config_status_idx'));
    }

    #[Test]
    public function migration_creates_sync_run_items_table_with_expected_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('sync_run_items'));
        $this->assertTrue(Schema::hasColumn('sync_run_items', 'product_id'));
        $this->assertTrue(Schema::hasColumn('sync_run_items', 'findings'));
        $this->assertTrue($this->indexExists('sync_run_items', 'sri_run_product_unique'));
        $this->assertTrue($this->indexExists('sync_run_items', 'sri_ws_id_unique'));
    }

    #[Test]
    public function products_workspace_composite_unique_exists(): void
    {
        $this->assertTrue($this->indexExists('products', 'products_workspace_id_id_unique'));
    }

    #[Test]
    public function cross_workspace_sync_run_to_configuration_insert_fails(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Workspace B', 'is_default' => false]);
        $account = $this->createSyncSupportAccountForWorkspace($workspaceA);
        $configuration = $this->createProductsSyncConfiguration($account);

        $this->expectException(QueryException::class);

        DB::table('sync_runs')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceB->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => str_repeat('a', 64),
            'mode' => SyncRunMode::Preview->value,
            'semantic_operation' => SyncSemanticOperation::Export->value,
            'status' => SyncRunStatus::Queued->value,
            'configuration_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function cross_workspace_sync_run_item_to_run_fails(): void
    {
        $fixture = $this->createPersistedRunFixture();
        $workspaceB = Workspace::query()->create(['name' => 'Workspace B', 'is_default' => false]);

        $this->expectException(QueryException::class);

        DB::table('sync_run_items')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceB->id,
            'sync_run_id' => $fixture['run']->id,
            'product_id' => $fixture['product']->id,
            'outcome' => SyncPreviewOutcome::Ready->value,
            'findings' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function cross_workspace_sync_run_item_to_product_fails(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Workspace B', 'is_default' => false]);
        $account = $this->createSyncSupportAccountForWorkspace($workspaceA);
        $configuration = $this->createProductsSyncConfiguration($account);
        $run = $this->createSyncRunRecord($account, $configuration);

        $foreignProductId = DB::table('products')->insertGetId([
            'workspace_id' => $workspaceB->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'FOREIGN-'.Str::random(6),
            'name' => 'Foreign product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('sync_run_items')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceA->id,
            'sync_run_id' => $run->id,
            'product_id' => $foreignProductId,
            'outcome' => SyncPreviewOutcome::Ready->value,
            'findings' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function deleting_product_referenced_by_sync_run_item_is_restricted(): void
    {
        $fixture = $this->createPersistedRunFixture(withItem: true);

        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $fixture['product']->id)->delete();
    }

    #[Test]
    public function deleting_sync_configuration_referenced_by_sync_run_is_restricted(): void
    {
        $fixture = $this->createPersistedRunFixture();

        $this->expectException(QueryException::class);
        SyncConfiguration::withoutWorkspaceScope()->whereKey($fixture['configuration']->id)->delete();
    }

    #[Test]
    public function deleting_sync_run_with_items_is_restricted(): void
    {
        $fixture = $this->createPersistedRunFixture(withItem: true);

        $this->expectException(QueryException::class);
        SyncRun::withoutWorkspaceScope()->whereKey($fixture['run']->id)->delete();
    }

    #[Test]
    public function deleting_initiating_user_nulls_sync_run_initiator_and_preserves_run(): void
    {
        $user = $this->createStaffUser(UserRole::Admin);
        $fixture = $this->createPersistedRunFixture(initiatedBy: $user);

        $user->delete();

        $run = SyncRun::withoutWorkspaceScope()->findOrFail($fixture['run']->id);
        $this->assertNull($run->initiated_by_user_id);
        $this->assertSame($fixture['run']->id, $run->id);
    }

    #[Test]
    public function duplicate_sync_run_item_product_pair_is_rejected(): void
    {
        $fixture = $this->createPersistedRunFixture(withItem: true);

        $this->expectException(QueryException::class);

        DB::table('sync_run_items')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $fixture['account']->workspace_id,
            'sync_run_id' => $fixture['run']->id,
            'product_id' => $fixture['product']->id,
            'outcome' => SyncPreviewOutcome::Warning->value,
            'findings' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function enum_and_json_casts_roundtrip_correctly(): void
    {
        $fixture = $this->createPersistedRunFixture(withItem: true);

        $run = SyncRun::withoutWorkspaceScope()->findOrFail($fixture['run']->id);
        $this->assertSame(SyncRunMode::Preview, $run->mode);
        $this->assertSame(SyncSemanticOperation::Export, $run->semantic_operation);
        $this->assertSame(SyncRunStatus::Queued, $run->status);
        $this->assertSame(['selection' => ['mode' => 'all_products']], $run->configuration_snapshot);

        $item = SyncRunItem::withoutWorkspaceScope()->findOrFail($fixture['item']->id);
        $this->assertSame(SyncPreviewOutcome::Ready, $item->outcome);
        $this->assertSame([], $item->findings);
    }

    #[Test]
    public function belongs_to_workspace_prevents_cross_workspace_model_reads(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Workspace B', 'is_default' => false]);
        $fixture = $this->createPersistedRunFixture();

        $this->setWorkspaceContext($workspaceB);

        $this->assertNull(SyncRun::query()->find($fixture['run']->id));
        $this->assertSame(0, SyncRun::query()->count());
    }

    #[Test]
    public function workspace_permission_catalogue_contains_eighth_run_sync_preview_permission(): void
    {
        $this->assertCount(8, WorkspacePermissions::catalogue());
        $this->assertContains(WorkspacePermissions::RUN_SYNC_PREVIEW, WorkspacePermissions::catalogue());
    }

    #[Test]
    public function adobe_products_export_supports_preview_only(): void
    {
        app()->forgetInstance(ConnectorProfileRegistry::class);

        $account = $this->createConnectorAccount();
        $resolver = app(ConnectorSyncSupportResolver::class);

        $this->assertTrue($resolver->supports($account, SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview));
        $this->assertFalse($resolver->supports($account, SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Live));
        $this->assertFalse($resolver->supports($account, SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview));

        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import],
            [SyncDataDomain::Products, SyncSemanticOperation::Export],
        ]);
    }

    #[Test]
    public function preview_queue_job_exists_without_merchant_route(): void
    {
        $this->assertTrue(class_exists(SyncPreviewRunJob::class));

        $routePaths = collect(app('router')->getRoutes())->map(
            static fn ($route): string => $route->uri(),
        );

        $this->assertFalse($routePaths->contains(static fn (string $uri): bool => str_contains($uri, 'preview-sync')));
        $this->assertFalse($routePaths->contains(static fn (string $uri): bool => str_contains($uri, 'sync-preview')));
    }

    #[Test]
    public function no_live_mutation_or_external_record_link_implementation_exists(): void
    {
        $this->assertFalse(class_exists(ExternalRecordLink::class));
        $this->assertTrue(class_exists(SyncPreviewAdmissionService::class));
        $this->assertTrue(class_exists(AdobeProductExportPreviewPlanner::class));
    }

    #[Test]
    public function sync_run_persistence_migration_rolls_back_cleanly(): void
    {
        $this->rollbackThrough('2026_08_16_110000_sync_run_persistence');

        $this->assertFalse(Schema::hasTable('sync_runs'));
        $this->assertFalse(Schema::hasTable('sync_run_items'));
        $this->assertTrue($this->indexExists('products', 'products_workspace_id_id_unique'));

        $this->artisan('migrate')->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('sync_runs'));
        $this->assertTrue(Schema::hasTable('sync_run_items'));
    }

    #[Test]
    public function revision_v3_migration_rollback_restores_v2_hashes(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding();
        $this->publishAuthoritativeSnapshot($account, ['rollback_key']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'rollback_key',
        );

        $expectedV2 = $this->migrationHashV2(
            ['import'],
            SyncConfigurationOperationalState::Enabled->value,
            [['field_binding_id' => $binding->id, 'external_field_key' => 'rollback_key']],
        );

        $this->rollbackThrough('2026_08_16_100000_sync_configuration_revision_v3');

        $actual = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;
        $this->assertSame($expectedV2, $actual);
        $this->assertTrue(
            FieldMapping::withoutWorkspaceScope()->where('sync_configuration_id', $configuration->id)->exists(),
        );

        $this->artisan('migrate')->assertExitCode(0);
    }

    /**
     * @return array{
     *     account: ConnectorAccount,
     *     configuration: SyncConfiguration,
     *     run: SyncRun,
     *     product: Product,
     *     item?: SyncRunItem
     * }
     */
    private function createPersistedRunFixture(
        bool $withItem = false,
        ?User $initiatedBy = null,
    ): array {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $product = $this->createProduct($account->workspace);
        $run = $this->createSyncRunRecord($account, $configuration, $initiatedBy);

        $result = [
            'account' => $account,
            'configuration' => $configuration,
            'run' => $run,
            'product' => $product,
        ];

        if ($withItem) {
            $result['item'] = SyncRunItem::withoutWorkspaceScope()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $account->workspace_id,
                'sync_run_id' => $run->id,
                'product_id' => $product->id,
                'outcome' => SyncPreviewOutcome::Ready,
                'findings' => [],
            ]);
        }

        return $result;
    }

    private function createSyncRunRecord(
        ConnectorAccount $account,
        SyncConfiguration $configuration,
        ?User $initiatedBy = null,
    ): SyncRun {
        return SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Queued,
            'initiated_by_user_id' => $initiatedBy?->id,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
        ]);
    }

    private function createProduct(Workspace $workspace): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-'.Str::random(8),
            'name' => 'Test product',
            'is_active' => true,
        ]);
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
     * @param  list<string>  $operations
     * @param  list<array{field_binding_id: string, external_field_key: string}>  $fieldMappings
     */
    private function migrationHashV2(array $operations, string $operationalState, array $fieldMappings): string
    {
        $migration = $this->revisionV3Migration();
        $reflection = new \ReflectionClass($migration);
        $hashMethod = $reflection->getMethod('hashRevisionV2');
        $hashMethod->setAccessible(true);
        $canonicalMethod = $reflection->getMethod('canonicalizePersistedOperations');
        $canonicalMethod->setAccessible(true);

        return $hashMethod->invoke(
            $migration,
            $canonicalMethod->invoke($migration, $operations),
            $operationalState,
            $fieldMappings,
        );
    }

    /**
     * @param  list<string>  $operations
     * @param  list<array{field_binding_id: string, external_field_key: string}>  $fieldMappings
     */
    private function migrationHashV3(array $operations, string $operationalState, array $fieldMappings): string
    {
        $migration = $this->revisionV3Migration();
        $reflection = new \ReflectionClass($migration);
        $hashMethod = $reflection->getMethod('hashRevisionV3');
        $hashMethod->setAccessible(true);
        $canonicalMethod = $reflection->getMethod('canonicalizePersistedOperations');
        $canonicalMethod->setAccessible(true);

        return $hashMethod->invoke(
            $migration,
            $canonicalMethod->invoke($migration, $operations),
            $operationalState,
            $fieldMappings,
        );
    }

    private function revisionV4Migration(): object
    {
        return require database_path('migrations/2026_08_17_120000_sync_configuration_revision_v4.php');
    }

    private function revisionV3Migration(): object
    {
        return require database_path('migrations/2026_08_16_100000_sync_configuration_revision_v3.php');
    }

    private function setWorkspaceContext(Workspace $workspace): void
    {
        $context = app(WorkspaceContext::class);
        $reflection = new \ReflectionProperty(WorkspaceContext::class, 'current');
        $reflection->setAccessible(true);
        $reflection->setValue($context, $workspace);
    }
}
