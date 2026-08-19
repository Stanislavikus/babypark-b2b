<?php

namespace Tests\Feature\Sync;

use App\Enums\ConnectorDirection;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\SyncConfiguration;
use App\Models\Workspace;
use App\Services\Sync\CreateSyncConfigurationInput;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\UpdateSyncConfigurationInput;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use App\Support\Sync\Exceptions\SyncConfigurationConflictException;
use App\Support\Sync\Exceptions\SyncExternalContextValidationException;
use App\Support\Sync\Exceptions\UnsupportedSyncOperationException;
use App\Support\Sync\SyncExternalContext;
use App\Support\Sync\SyncOperationSet;
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
use Tests\TestCase;

class SyncConfigurationFoundationTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function migration_creates_sync_configurations_table_with_expected_keys(): void
    {
        $this->assertTrue(Schema::hasTable('sync_configurations'));
        $this->assertTrue($this->indexExists('sync_configurations', 'sc_ws_id_unique'));
        $this->assertTrue($this->indexExists('sync_configurations', 'sc_account_domain_context_unique'));
        $this->assertTrue(Schema::hasColumn('sync_configurations', 'external_context_key'));
        $this->assertTrue(Schema::hasColumn('sync_configurations', 'configuration_revision'));
    }

    #[Test]
    public function migration_rolls_back_cleanly(): void
    {
        $this->rollbackThrough('2026_08_12_100000_sync_configuration_foundation');

        $this->assertFalse(Schema::hasTable('sync_configurations'));

        $this->artisan('migrate');
    }

    #[Test]
    public function model_casts_are_correct(): void
    {
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
        ]);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->syncService()->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Import],
            operationalState: SyncConfigurationOperationalState::Paused,
        ));

        $configuration->refresh();

        $this->assertInstanceOf(SyncDataDomain::class, $configuration->data_domain);
        $this->assertSame(SyncDataDomain::Products, $configuration->data_domain);
        $this->assertSame([], $configuration->external_context);
        $this->assertInstanceOf(SyncConfigurationOperationalState::class, $configuration->operational_state);
        $this->assertSame(SyncConfigurationOperationalState::Paused, $configuration->operational_state);
        $this->assertSame(['import'], $configuration->enabled_operations);
    }

    #[Test]
    public function composite_foreign_key_rejects_cross_workspace_connector_account(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $account = $this->createConnectorAccount($workspaceA);

        $this->expectException(QueryException::class);

        DB::table('sync_configurations')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceB->id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products->value,
            'external_context' => json_encode([], JSON_THROW_ON_ERROR),
            'external_context_key' => SyncExternalContext::default()->uniquenessKey(),
            'enabled_operations' => json_encode(['import'], JSON_THROW_ON_ERROR),
            'operational_state' => SyncConfigurationOperationalState::Enabled->value,
            'configuration_revision' => hash('sha256', 'revision'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function duplicate_identity_for_same_account_domain_and_default_context_is_rejected(): void
    {
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
        ]);

        $account = $this->createSyncSupportAccount();
        $service = $this->syncService();

        $service->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Import],
        ));

        $this->expectException(SyncConfigurationConflictException::class);

        $service->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Import],
        ));
    }

    #[Test]
    public function default_context_cannot_bypass_uniqueness_with_null_semantics(): void
    {
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
        ]);

        $account = $this->createSyncSupportAccount();

        $this->createSyncConfigurationViaEloquent($account, [
            'external_context' => [],
            'configuration_revision' => hash('sha256', 'seed'),
        ]);

        $this->expectException(QueryException::class);

        $this->createSyncConfigurationViaEloquent($account, [
            'external_context' => [],
            'configuration_revision' => hash('sha256', 'seed-2'),
        ]);
    }

    #[Test]
    public function caller_supplied_external_context_key_is_not_trusted_and_duplicate_identity_is_rejected(): void
    {
        $account = $this->createSyncSupportAccount();
        $context = ['region' => 'eu'];
        $derivedKey = SyncExternalContext::fromPayload($context)->uniquenessKey();
        $mismatchedKey = str_repeat('a', 64);

        $configuration = (new SyncConfiguration)->forceFill([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products,
            'external_context' => $context,
            'external_context_key' => $mismatchedKey,
            'enabled_operations' => ['import'],
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'configuration_revision' => hash('sha256', 'mismatch-probe'),
        ]);
        $configuration->save();
        $configuration->refresh();

        $this->assertSame($derivedKey, $configuration->external_context_key);
        $this->assertNotSame($mismatchedKey, $configuration->external_context_key);

        $this->expectException(QueryException::class);

        (new SyncConfiguration)->forceFill([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products,
            'external_context' => $context,
            'external_context_key' => str_repeat('b', 64),
            'enabled_operations' => ['import'],
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'configuration_revision' => hash('sha256', 'mismatch-probe-2'),
        ])->save();
    }

    #[Test]
    public function canonically_equivalent_external_contexts_cannot_create_duplicate_identities(): void
    {
        $account = $this->createSyncSupportAccount();
        $expectedContext = SyncExternalContext::fromPayload(['region' => 'eu', 'channel' => 'retail']);

        $first = $this->createSyncConfigurationViaEloquent($account, [
            'external_context' => ['region' => 'eu', 'channel' => 'retail'],
            'configuration_revision' => hash('sha256', 'canonical-a'),
        ]);

        $expectedContext = SyncExternalContext::fromPayload(['region' => 'eu', 'channel' => 'retail']);
        $persistedContext = SyncExternalContext::fromPayload($first->external_context);

        $this->assertTrue($expectedContext->equals($persistedContext));
        $this->assertSame($expectedContext->uniquenessKey(), $first->external_context_key);

        $this->expectException(QueryException::class);

        $this->createSyncConfigurationViaEloquent($account, [
            'external_context' => ['channel' => 'retail', 'region' => 'eu'],
            'configuration_revision' => hash('sha256', 'canonical-b'),
        ]);
    }

    #[Test]
    public function different_external_contexts_remain_independently_persistable_with_distinct_keys(): void
    {
        $account = $this->createSyncSupportAccount();

        $first = $this->createSyncConfigurationViaEloquent($account, [
            'external_context' => ['region' => 'eu'],
            'configuration_revision' => hash('sha256', 'context-a'),
        ]);

        $second = $this->createSyncConfigurationViaEloquent($account, [
            'external_context' => ['region' => 'us'],
            'configuration_revision' => hash('sha256', 'context-b'),
        ]);

        $this->assertNotSame($first->external_context_key, $second->external_context_key);
        $this->assertSame(['region' => 'eu'], $first->external_context);
        $this->assertSame(['region' => 'us'], $second->external_context);
    }

    #[Test]
    public function external_context_key_is_rederived_when_external_context_changes_on_save(): void
    {
        $account = $this->createSyncSupportAccount();

        $configuration = $this->createSyncConfigurationViaEloquent($account, [
            'external_context' => ['region' => 'eu'],
            'configuration_revision' => hash('sha256', 'update-a'),
        ]);

        $originalKey = $configuration->external_context_key;

        $configuration->external_context = ['region' => 'us'];
        $configuration->save();
        $configuration->refresh();

        $expectedKey = SyncExternalContext::fromPayload(['region' => 'us'])->uniquenessKey();

        $this->assertNotSame($originalKey, $configuration->external_context_key);
        $this->assertSame($expectedKey, $configuration->external_context_key);
        $this->assertSame(['region' => 'us'], $configuration->external_context);
    }

    #[Test]
    public function force_fill_with_mismatched_external_context_key_is_overwritten_on_save(): void
    {
        $account = $this->createSyncSupportAccount();
        $context = ['lane' => 'import'];

        $configuration = (new SyncConfiguration)->forceFill([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products,
            'external_context' => $context,
            'external_context_key' => str_repeat('c', 64),
            'enabled_operations' => ['import'],
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'configuration_revision' => hash('sha256', 'force-fill'),
        ]);
        $configuration->save();
        $configuration->refresh();

        $this->assertSame(
            SyncExternalContext::fromPayload($context)->uniquenessKey(),
            $configuration->external_context_key,
        );
    }

    #[Test]
    public function explicit_default_external_context_persists_successfully(): void
    {
        $account = $this->createSyncSupportAccount();

        $configuration = $this->createSyncConfigurationViaEloquent($account, [
            'external_context' => [],
            'configuration_revision' => hash('sha256', 'explicit-default'),
        ]);

        $this->assertSame([], $configuration->external_context);
        $this->assertSame(
            SyncExternalContext::default()->uniquenessKey(),
            $configuration->external_context_key,
        );
        $this->assertDatabaseCount('sync_configurations', 1);
    }

    #[Test]
    public function valid_associative_external_context_persists_and_canonicalizes_on_save(): void
    {
        $account = $this->createSyncSupportAccount();

        $configuration = $this->createSyncConfigurationViaEloquent($account, [
            'external_context' => ['channel' => 'retail', 'region' => 'eu'],
            'configuration_revision' => hash('sha256', 'valid-associative'),
        ]);

        $expectedContext = SyncExternalContext::fromPayload(['channel' => 'retail', 'region' => 'eu']);
        $persistedContext = SyncExternalContext::fromPayload($configuration->external_context);

        $this->assertTrue($expectedContext->equals($persistedContext));
        $this->assertSame($expectedContext->uniquenessKey(), $configuration->external_context_key);
    }

    #[Test]
    public function string_external_context_is_rejected_on_save(): void
    {
        $account = $this->createSyncSupportAccount();

        $this->expectException(SyncExternalContextValidationException::class);
        $this->expectExceptionMessage('External context must be a JSON object.');

        $this->attemptInvalidExternalContextSave($account, 'this-is-not-a-json-object');

        $this->assertDatabaseCount('sync_configurations', 0);
    }

    #[Test]
    public function null_external_context_is_rejected_on_save(): void
    {
        $account = $this->createSyncSupportAccount();

        $this->expectException(SyncExternalContextValidationException::class);
        $this->expectExceptionMessage('External context must be a JSON object.');

        $this->attemptInvalidExternalContextSave($account, null);

        $this->assertDatabaseCount('sync_configurations', 0);
    }

    #[Test]
    public function scalar_non_array_external_context_is_rejected_on_save(): void
    {
        $account = $this->createSyncSupportAccount();

        $this->expectException(SyncExternalContextValidationException::class);
        $this->expectExceptionMessage('External context must be a JSON object.');

        $this->attemptInvalidExternalContextSave($account, 42);

        $this->assertDatabaseCount('sync_configurations', 0);
    }

    #[Test]
    public function list_shaped_external_context_is_rejected_on_save(): void
    {
        $account = $this->createSyncSupportAccount();

        $this->expectException(SyncExternalContextValidationException::class);
        $this->expectExceptionMessage('External context payload must be a JSON object.');

        $this->attemptInvalidExternalContextSave($account, ['a']);

        $this->assertDatabaseCount('sync_configurations', 0);
    }

    #[Test]
    public function invalid_external_context_cannot_silently_collide_with_existing_default_context_identity(): void
    {
        $account = $this->createSyncSupportAccount();

        $this->createSyncConfigurationViaEloquent($account, [
            'external_context' => [],
            'configuration_revision' => hash('sha256', 'existing-default'),
        ]);

        try {
            $this->attemptInvalidExternalContextSave($account, null);
            $this->fail('Expected SyncExternalContextValidationException was not thrown.');
        } catch (SyncExternalContextValidationException) {
            // expected
        }

        $this->assertDatabaseCount('sync_configurations', 1);
        $this->assertSame(
            SyncExternalContext::default()->uniquenessKey(),
            SyncConfiguration::withoutWorkspaceScope()->sole()->external_context_key,
        );
    }

    #[Test]
    public function same_domain_and_default_context_on_another_account_is_allowed(): void
    {
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
        ]);

        $accountA = $this->createSyncSupportAccount(['name' => 'Account A']);
        $accountB = $this->createSyncSupportAccount(['name' => 'Account B']);
        $service = $this->syncService();

        $service->create($accountA, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Import],
        ));

        $configuration = $service->create($accountB, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Import],
        ));

        $this->assertNotSame($accountA->id, $configuration->connector_account_id);
    }

    #[Test]
    public function same_account_and_domain_with_different_external_context_is_allowed(): void
    {
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
        ]);

        $account = $this->createSyncSupportAccount();
        $service = $this->syncService();

        $service->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Import],
        ));

        $configuration = $service->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::fromPayload(['region' => 'eu']),
            enabledOperations: [SyncSemanticOperation::Import],
        ));

        $this->assertSame(['region' => 'eu'], $configuration->external_context);
    }

    #[Test]
    public function import_only_export_only_and_both_operation_sets_are_representable(): void
    {
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview],
        ]);

        $account = $this->createSyncSupportAccount();
        $service = $this->syncService();

        $importOnly = $service->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::fromPayload(['lane' => 'import']),
            enabledOperations: [SyncSemanticOperation::Import],
        ));

        $exportOnly = $service->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::fromPayload(['lane' => 'export']),
            enabledOperations: [SyncSemanticOperation::Export],
        ));

        $both = $service->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::fromPayload(['lane' => 'both']),
            enabledOperations: [SyncSemanticOperation::Import, SyncSemanticOperation::Export],
        ));

        $this->assertSame(['import'], $importOnly->enabled_operations);
        $this->assertSame(['export'], $exportOnly->enabled_operations);
        $this->assertSame(['export', 'import'], $both->enabled_operations);
    }

    #[Test]
    public function operation_set_canonicalization_treats_reordered_and_duplicate_inputs_as_equivalent(): void
    {
        $importExport = SyncOperationSet::fromOperations([
            SyncSemanticOperation::Import,
            SyncSemanticOperation::Export,
        ]);

        $exportImportDuplicate = SyncOperationSet::fromOperations([
            SyncSemanticOperation::Export,
            SyncSemanticOperation::Import,
            SyncSemanticOperation::Import,
        ]);

        $this->assertTrue($importExport->equals($exportImportDuplicate));
        $this->assertSame(['export', 'import'], $importExport->values());
    }

    #[Test]
    public function supported_pairs_can_be_configured_through_runtime_profile_truth(): void
    {
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview],
        ]);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->syncService()->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Import, SyncSemanticOperation::Export],
        ));

        $this->assertSame(['export', 'import'], $configuration->enabled_operations);
    }

    #[Test]
    public function unsupported_domain_operation_pair_fails_closed(): void
    {
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
        ]);

        $account = $this->createSyncSupportAccount();

        $this->expectException(UnsupportedSyncOperationException::class);
        $this->expectExceptionMessage('[products/export]');

        $this->syncService()->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Export],
        ));
    }

    #[Test]
    public function adding_unsupported_operation_to_valid_configuration_fails_atomically(): void
    {
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
        ]);

        $account = $this->createSyncSupportAccount();
        $service = $this->syncService();

        $configuration = $service->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Import],
        ));

        $originalRevision = $configuration->configuration_revision;

        try {
            $service->update($account, $configuration->id, new UpdateSyncConfigurationInput(
                enabledOperations: [SyncSemanticOperation::Import, SyncSemanticOperation::Export],
            ));
            $this->fail('Expected UnsupportedSyncOperationException was not thrown.');
        } catch (UnsupportedSyncOperationException) {
            // expected
        }

        $configuration->refresh();

        $this->assertSame(['import'], $configuration->enabled_operations);
        $this->assertSame($originalRevision, $configuration->configuration_revision);
    }

    #[Test]
    public function adobe_commerce_profile_supports_products_export_preview_only(): void
    {
        $account = $this->createConnectorAccount();

        $resolver = app(ConnectorSyncSupportResolver::class);

        $this->assertFalse($resolver->supports($account, SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview));
        $this->assertTrue($resolver->supports($account, SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview));
        $this->assertFalse($resolver->supports($account, SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Live));

        $this->expectException(UnsupportedSyncOperationException::class);

        $this->syncService()->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Import],
        ));
    }

    #[Test]
    public function connector_definition_direction_is_not_used_as_execution_truth(): void
    {
        $definition = ConnectorDefinition::query()->where('code', 'adobe_commerce')->firstOrFail();
        $definition->direction = ConnectorDirection::Both;
        $definition->save();

        $account = $this->createConnectorAccount();
        $resolver = app(ConnectorSyncSupportResolver::class);

        $this->assertFalse($resolver->supports($account, SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview));
    }

    #[Test]
    public function semantic_mutation_increments_revision_and_no_op_does_not(): void
    {
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Preview],
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview],
        ]);

        $account = $this->createSyncSupportAccount();
        $service = $this->syncService();

        $configuration = $service->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Import],
        ));

        $initialRevision = $configuration->configuration_revision;

        $reorderedNoOp = $service->update($account, $configuration->id, new UpdateSyncConfigurationInput(
            enabledOperations: [SyncSemanticOperation::Import],
        ));

        $this->assertSame($initialRevision, $reorderedNoOp->configuration_revision);

        $paused = $service->update($account, $configuration->id, new UpdateSyncConfigurationInput(
            operationalState: SyncConfigurationOperationalState::Paused,
        ));

        $this->assertNotSame($initialRevision, $paused->configuration_revision);

        $withBoth = $service->update($account, $configuration->id, new UpdateSyncConfigurationInput(
            enabledOperations: [SyncSemanticOperation::Import, SyncSemanticOperation::Export],
        ));

        $this->assertNotSame($paused->configuration_revision, $withBoth->configuration_revision);

        $canonicalReorder = $service->update($account, $configuration->id, new UpdateSyncConfigurationInput(
            enabledOperations: [SyncSemanticOperation::Export, SyncSemanticOperation::Import],
        ));

        $this->assertSame($withBoth->configuration_revision, $canonicalReorder->configuration_revision);
    }

    private function syncService(): SyncConfigurationService
    {
        return app(SyncConfigurationService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSyncConfigurationViaEloquent(ConnectorAccount $account, array $overrides = []): SyncConfiguration
    {
        $configuration = SyncConfiguration::withoutWorkspaceScope()->create(array_merge([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products,
            'external_context' => [],
            'enabled_operations' => ['import'],
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'configuration_revision' => hash('sha256', 'eloquent-fixture'),
        ], $overrides));

        return $configuration->refresh();
    }

    private function attemptInvalidExternalContextSave(ConnectorAccount $account, mixed $externalContext): void
    {
        (new SyncConfiguration)->forceFill([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products,
            'external_context' => $externalContext,
            'enabled_operations' => ['import'],
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'configuration_revision' => hash('sha256', 'invalid-context'),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSyncSupportAccount(array $overrides = []): ConnectorAccount
    {
        return $this->createConnectorAccount(null, array_merge([
            'auth_profile' => 'test_sync_support',
        ], $overrides));
    }

    private function rollbackThrough(string $targetMigration): void
    {
        $migrations = DB::table('migrations')
            ->orderByDesc('batch')
            ->orderByDesc('migration')
            ->pluck('migration')
            ->values();

        $position = $migrations->search($targetMigration);

        $this->assertNotSame(
            false,
            $position,
            "Target migration is not recorded as applied: {$targetMigration}",
        );

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
