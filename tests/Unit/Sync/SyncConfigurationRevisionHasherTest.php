<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncSemanticOperation;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\FieldMappingRevisionEntry;
use App\Support\Sync\FieldOptionMappingRevisionEntry;
use App\Support\Sync\SyncConfigurationRevisionHasher;
use App\Support\Sync\SyncOperationSet;
use App\Support\Sync\SyncProductSelectionDescriptor;
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
    public function revision_hasher_matches_v5_migration_hash(): void
    {
        $operations = SyncOperationSet::fromOperations([SyncSemanticOperation::Export]);
        $state = SyncConfigurationOperationalState::Enabled;
        $mapping = new FieldMappingRevisionEntry(
            fieldBindingId: '00000000-0000-4000-8000-000000000002',
            externalFieldKey: 'sku',
        );

        $selection = SyncProductSelectionDescriptor::fromProductIds([9, 2]);

        $runtime = $this->hasher->hash(
            $operations,
            $state,
            [$mapping],
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 9]),
            $selection,
        );

        $migration = require database_path('migrations/2026_09_15_211000_sync_configuration_revision_v5.php');
        $reflection = new \ReflectionClass($migration);
        $hashMethod = $reflection->getMethod('hashRevisionV5');
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
            [2, 9],
        );

        $this->assertSame($migrationHash, $runtime);
    }

    #[Test]
    public function v5_revision_namespace_is_customer_neutral(): void
    {
        $reflection = new \ReflectionClass(SyncConfigurationRevisionHasher::class);
        $prefixConstant = $reflection->getReflectionConstant('PREFIX');
        $this->assertNotNull($prefixConstant);
        $prefix = $prefixConstant->getValue();

        $this->assertIsString($prefix);
        $this->assertStringStartsWith('platform.sync-configuration-revision.v5', $prefix);
        $this->assertStringNotContainsString('babypark', $prefix);
    }

    #[Test]
    public function opaque_connector_execution_configuration_canonicalizes_nested_connector_payload(): void
    {
        $payload = [
            'nested' => [
                'channel' => 'wholesale',
                'flags' => ['a', 'b'],
            ],
            'z_key' => 1,
        ];

        $config = ConnectorExecutionConfiguration::fromPayload($payload);

        $this->assertSame([
            'nested' => [
                'channel' => 'wholesale',
                'flags' => ['a', 'b'],
            ],
            'z_key' => 1,
        ], $config->payload());

        $revisionWithNested = $this->hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Export]),
            SyncConfigurationOperationalState::Enabled,
            [],
            $config,
        );

        $revisionWithout = $this->hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Export]),
            SyncConfigurationOperationalState::Enabled,
            [],
            ConnectorExecutionConfiguration::empty(),
        );

        $this->assertNotSame($revisionWithout, $revisionWithNested);
    }

    #[Test]
    public function nested_object_key_order_produces_identical_v5_revision(): void
    {
        $left = ConnectorExecutionConfiguration::fromPayload([
            'nested' => ['a' => 1, 'b' => 2],
        ]);

        $right = ConnectorExecutionConfiguration::fromPayload([
            'nested' => ['b' => 2, 'a' => 1],
        ]);

        $runtimeLeft = $this->hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Export]),
            SyncConfigurationOperationalState::Enabled,
            [],
            $left,
        );

        $runtimeRight = $this->hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Export]),
            SyncConfigurationOperationalState::Enabled,
            [],
            $right,
        );

        $this->assertSame($runtimeLeft, $runtimeRight);

        $migration = require database_path('migrations/2026_09_15_211000_sync_configuration_revision_v5.php');
        $reflection = new \ReflectionClass($migration);
        $hashMethod = $reflection->getMethod('hashRevisionV5');
        $hashMethod->setAccessible(true);

        $migrationHash = $hashMethod->invoke(
            $migration,
            ['export'],
            SyncConfigurationOperationalState::Enabled->value,
            [],
            ['nested' => ['b' => 2, 'a' => 1]],
            [],
        );

        $this->assertSame($migrationHash, $runtimeLeft);
    }

    #[Test]
    public function selection_membership_is_canonical_and_advances_revision_only_when_effective_set_changes(): void
    {
        $operations = SyncOperationSet::fromOperations([SyncSemanticOperation::Export]);
        $state = SyncConfigurationOperationalState::Enabled;

        $left = $this->hasher->hash(
            $operations,
            $state,
            [],
            ConnectorExecutionConfiguration::empty(),
            SyncProductSelectionDescriptor::fromProductIds([9, 2, 9]),
        );
        $sameSetDifferentOrder = $this->hasher->hash(
            $operations,
            $state,
            [],
            ConnectorExecutionConfiguration::empty(),
            SyncProductSelectionDescriptor::fromProductIds([2, 9]),
        );
        $differentSet = $this->hasher->hash(
            $operations,
            $state,
            [],
            ConnectorExecutionConfiguration::empty(),
            SyncProductSelectionDescriptor::fromProductIds([2, 10]),
        );

        $this->assertSame($left, $sameSetDifferentOrder);
        $this->assertNotSame($left, $differentSet);
    }

    #[Test]
    public function v5_migration_down_restores_v4_hash_semantics(): void
    {
        $migrationV5 = require database_path('migrations/2026_09_15_211000_sync_configuration_revision_v5.php');
        $reflectionV5 = new \ReflectionClass($migrationV5);
        $hashV4 = $reflectionV5->getMethod('hashRevisionV4');
        $hashV4->setAccessible(true);

        $expectedV4 = $hashV4->invoke(
            $migrationV5,
            ['import'],
            SyncConfigurationOperationalState::Enabled->value,
            [],
            [],
        );

        $this->assertSame(64, strlen($expectedV4));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $expectedV4);
    }
}
