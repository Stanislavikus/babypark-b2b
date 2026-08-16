<?php

namespace Tests\Unit\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncSemanticOperation;
use App\Support\Sync\Exceptions\SyncOperationSetValidationException;
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
    public function empty_field_mappings_are_canonical_in_revision_payload(): void
    {
        $withDefault = $this->hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Import]),
            SyncConfigurationOperationalState::Enabled,
        );

        $withExplicitEmpty = $this->hasher->hash(
            SyncOperationSet::fromOperations([SyncSemanticOperation::Import]),
            SyncConfigurationOperationalState::Enabled,
            [],
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
    public function operation_set_rejects_empty_configuration(): void
    {
        $this->expectException(SyncOperationSetValidationException::class);

        SyncOperationSet::fromOperations([]);
    }

    #[Test]
    public function selection_all_products_advances_revision_from_v2_equivalent(): void
    {
        $operations = SyncOperationSet::fromOperations([SyncSemanticOperation::Import]);
        $state = SyncConfigurationOperationalState::Enabled;

        $v3 = $this->hasher->hash($operations, $state, []);

        $migration = require database_path('migrations/2026_08_16_100000_sync_configuration_revision_v3.php');
        $reflection = new \ReflectionClass($migration);
        $hashV2 = $reflection->getMethod('hashRevisionV2');
        $hashV2->setAccessible(true);
        $canonical = $reflection->getMethod('canonicalizePersistedOperations');
        $canonical->setAccessible(true);

        $v2Equivalent = $hashV2->invoke(
            $migration,
            $canonical->invoke($migration, ['import']),
            $state->value,
            [],
        );

        $this->assertNotSame($v2Equivalent, $v3);
    }
}
