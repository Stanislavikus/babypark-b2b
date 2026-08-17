<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\FieldMapping;
use App\Models\FieldOptionMapping;
use App\Models\SyncConfiguration;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\FieldOptionMappingMutationService;
use App\Services\Sync\SyncConfigurationService;
use App\Support\Sync\ConnectorExecutionConfiguration;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

class FieldOptionMappingTest extends TestCase
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
    public function migration_creates_field_option_mappings_table(): void
    {
        $this->assertTrue(Schema::hasTable('field_option_mappings'));
        $this->assertTrue(Schema::hasColumn('sync_configurations', 'connector_execution_configuration'));
        $this->assertTrue($this->indexExists('field_mappings', 'fm_ws_id_unique'));
    }

    #[Test]
    public function confirm_replace_and_remove_mutate_option_mappings_and_revision(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productVariantBinding('color');
        $this->publishAuthoritativeSnapshot($account, ['color']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'color',
        );

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->sole();

        $revisionBefore = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;

        app(FieldOptionMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $mapping->id,
            'blue',
            '93',
        );

        $revisionAfterConfirm = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;
        $this->assertNotSame($revisionBefore, $revisionAfterConfirm);

        $this->assertDatabaseHas('field_option_mappings', [
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '93',
        ]);

        app(FieldOptionMappingMutationService::class)->replace(
            $account,
            $configuration->id,
            $mapping->id,
            'blue',
            '93',
            newExternalOptionValue: '94',
        );

        $this->assertDatabaseHas('field_option_mappings', [
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '94',
        ]);

        app(FieldOptionMappingMutationService::class)->remove(
            $account,
            $configuration->id,
            $mapping->id,
            'blue',
            '94',
        );

        $this->assertDatabaseMissing('field_option_mappings', [
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
        ]);
    }

    #[Test]
    public function field_mapping_delete_cascades_option_mappings(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productVariantBinding('color');
        $this->publishAuthoritativeSnapshot($account, ['color']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'color',
        );

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->sole();

        app(FieldOptionMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $mapping->id,
            'blue',
            '93',
        );

        $mapping->delete();

        $this->assertSame(0, FieldOptionMapping::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function connector_execution_configuration_updates_revision(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $before = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;

        app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 4]),
        );

        $after = SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->configuration_revision;
        $this->assertNotSame($before, $after);
        $this->assertSame(4, SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->connectorExecutionConfiguration()->attributeSetId());
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }
        }

        return false;
    }
}
