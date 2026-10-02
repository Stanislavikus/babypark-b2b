<?php

namespace Tests\Feature\Sync;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;
use App\Models\FieldOptionMapping;
use App\Models\SyncConfiguration;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\FieldOptionMappingMutationService;
use App\Services\Sync\SyncConfigurationService;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionConfiguration;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\FieldMappingValidationException;
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
        $this->assertSame(
            4,
            AdobeProductExportExecutionConfiguration::fromPayload(
                SyncConfiguration::withoutWorkspaceScope()->findOrFail($configuration->id)->connectorExecutionConfiguration()->payload(),
            )->attributeSetId,
        );
    }

    #[Test]
    public function confirm_accepts_product_level_select_option_mapping(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $workspace = $account->workspace;
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'product_select_test',
            'data_type' => AttributeDataType::Select,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Тест'],
            'description' => null,
            'validation_rules' => [
                'options' => [
                    ['code' => 'cotton', 'labels' => ['uk' => 'Бавовна']],
                ],
            ],
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);
        $binding = FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
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
        $this->publishAuthoritativeSnapshot($account, ['material']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'material',
        );

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->sole();

        app(FieldOptionMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $mapping->id,
            'cotton',
            '148',
        );

        $this->assertDatabaseHas('field_option_mappings', [
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'cotton',
            'external_option_value' => '148',
        ]);
    }

    #[Test]
    public function confirm_rejects_non_select_field_option_mapping(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productVariantBinding('sku');
        $this->publishAuthoritativeSnapshot($account, ['sku']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'sku',
        );

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->sole();

        $this->expectException(FieldMappingValidationException::class);

        app(FieldOptionMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $mapping->id,
            'not-a-select-code',
            '93',
        );
    }

    #[Test]
    public function confirm_rejects_unknown_internal_option_key(): void
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

        $this->expectException(FieldMappingValidationException::class);

        app(FieldOptionMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $mapping->id,
            'purple',
            '93',
        );
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            $database = $connection->getDatabaseName();
            $result = $connection->select(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$database, $table, $indexName],
            );

            return $result !== [];
        }

        return false;
    }
}
