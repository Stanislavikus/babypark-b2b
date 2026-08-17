<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncSemanticOperation;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\FieldMappingRevisionEntry;
use App\Support\Sync\FieldOptionMappingRevisionEntry;
use App\Support\Sync\SyncConfigurationRevisionHasher;
use App\Support\Sync\SyncOperationSet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncConfigurationRevisionHasherTest extends TestCase
{
    private SyncConfigurationRevisionHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hasher = new SyncConfigurationRevisionHasher;
    }

    #[Test]
    public function initial_revision_is_deterministic_for_import_only(): void
    {
        $revision = $this->hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Import]),
            SyncConfigurationOperationalState::Enabled,
            [],
        );

        $this->assertSame(64, strlen($revision));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $revision);
        $this->assertSame(
            $revision,
            $this->hasher->hash(
                SyncOperationSet::fromOperations([SyncSemanticOperation::Import]),
                SyncConfigurationOperationalState::Enabled,
                [],
            ),
        );
    }

    #[Test]
    public function empty_field_mappings_and_connector_config_are_canonical(): void
    {
        $withDefault = $this->hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Import]),
            SyncConfigurationOperationalState::Enabled,
        );

        $withExplicitEmpty = $this->hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Import]),
            SyncConfigurationOperationalState::Enabled,
            [],
            ConnectorExecutionConfiguration::empty(),
        );

        $this->assertSame($withDefault, $withExplicitEmpty);
    }

    #[Test]
    public function canonical_operation_order_does_not_change_revision(): void
    {
        $importExport = $this->hasher->hash(
            SyncOperationSet::fromOperations([
                SyncSemanticOperation::Import,
                SyncSemanticOperation::Export,
            ]),
            SyncConfigurationOperationalState::Enabled,
            [],
        );

        $exportImport = $this->hasher->hash(
            SyncOperationSet::fromOperations([
                SyncSemanticOperation::Export,
                SyncSemanticOperation::Import,
            ]),
            SyncConfigurationOperationalState::Enabled,
            [],
        );

        $this->assertSame($importExport, $exportImport);
    }

    #[Test]
    public function duplicate_operations_do_not_change_revision(): void
    {
        $single = $this->hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Import]),
            SyncConfigurationOperationalState::Enabled,
        );

        $duplicated = $this->hasher->hash(
            SyncOperationSet::fromOperations([
                SyncSemanticOperation::Import,
                SyncSemanticOperation::Import,
            ]),
            SyncConfigurationOperationalState::Enabled,
        );

        $this->assertSame($single, $duplicated);
    }

    #[Test]
    public function operational_state_change_advances_revision(): void
    {
        $enabled = $this->hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Import]),
            SyncConfigurationOperationalState::Enabled,
        );

        $paused = $this->hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Import]),
            SyncConfigurationOperationalState::Paused,
        );

        $this->assertNotSame($enabled, $paused);
    }

    #[Test]
    public function operation_set_change_advances_revision(): void
    {
        $importOnly = $this->hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Import]),
            SyncConfigurationOperationalState::Enabled,
        );

        $importExport = $this->hasher->hash(
            SyncOperationSet::fromOperations([
                SyncSemanticOperation::Import,
                SyncSemanticOperation::Export,
            ]),
            SyncConfigurationOperationalState::Enabled,
        );

        $this->assertNotSame($importOnly, $importExport);
    }

    #[Test]
    public function option_mappings_and_connector_config_advance_revision_from_v3_equivalent(): void
    {
        $operations = SyncOperationSet::fromOperations([SyncSemanticOperation::Import]);
        $state = SyncConfigurationOperationalState::Enabled;
        $mapping = new FieldMappingRevisionEntry(
            fieldBindingId: '00000000-0000-4000-8000-000000000001',
            externalFieldKey: 'color',
            optionMappings: [
                new FieldOptionMappingRevisionEntry('blue', '93'),
            ],
        );

        $v4 = $this->hasher->hash(
            $operations,
            $state,
            [$mapping],
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 4]),
        );

        $migration = require database_path('migrations/2026_08_16_100000_sync_configuration_revision_v3.php');
        $reflection = new \ReflectionClass($migration);
        $hashV3 = $reflection->getMethod('hashRevisionV3');
        $hashV3->setAccessible(true);
        $canonical = $reflection->getMethod('canonicalizePersistedOperations');
        $canonical->setAccessible(true);

        $v3Equivalent = $hashV3->invoke(
            $migration,
            $canonical->invoke($migration, ['import']),
            $state->value,
            [],
        );

        $this->assertNotSame($v3Equivalent, $v4);
    }

    #[Test]
    public function revision_hasher_matches_v4_migration_hash(): void
    {
        $operations = SyncOperationSet::fromOperations([SyncSemanticOperation::Export]);
        $state = SyncConfigurationOperationalState::Enabled;
        $mapping = new FieldMappingRevisionEntry(
            fieldBindingId: '00000000-0000-4000-8000-000000000002',
            externalFieldKey: 'sku',
        );

        $runtime = $this->hasher->hash(
            $operations,
            $state,
            [$mapping],
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 9]),
        );

        $migration = require database_path('migrations/2026_08_17_120000_sync_configuration_revision_v4.php');
        $reflection = new \ReflectionClass($migration);
        $hashMethod = $reflection->getMethod('hashRevisionV4');
        $hashMethod->setAccessible(true);

        $migrationHash = $hashMethod->invoke(
            $migration,
            ['export'],
            $state->value,
            [[
                'field_binding_id' => '00000000-0000-4000-8000-000000000002',
                'external_field_key' => 'sku',
                'option_mappings' => [],
            ]],
            ['attribute_set_id' => 9],
        );

        $this->assertSame($migrationHash, $runtime);
    }
}
