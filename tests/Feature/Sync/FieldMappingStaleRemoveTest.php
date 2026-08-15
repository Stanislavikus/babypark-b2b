<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\SyncConfiguration;
use App\Services\Sync\FieldMappingMutationService;
use App\Support\Sync\Exceptions\FieldMappingValidationException;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

class FieldMappingStaleRemoveTest extends TestCase
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
        ]);
    }

    #[Test]
    public function stale_remove_rejects_and_preserves_replacement_mapping(): void
    {
        $account = $this->createConnectorAccount(null, ['auth_profile' => 'test_sync_support']);
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productBinding('name');
        $service = app(FieldMappingMutationService::class);

        $this->publishAuthoritativeSnapshot($account, ['field_x', 'field_y']);

        $service->confirm($account, $configuration->id, $binding->id, 'field_x');
        $revisionAfterConfirm = SyncConfiguration::withoutWorkspaceScope()
            ->findOrFail($configuration->id)
            ->configuration_revision;

        $service->replace(
            $account,
            $configuration->id,
            $binding->id,
            'field_x',
            newExternalFieldKey: 'field_y',
        );

        $this->expectException(FieldMappingValidationException::class);

        try {
            $service->remove($account, $configuration->id, $binding->id, 'field_x');
        } finally {
            $this->assertDatabaseHas('field_mappings', [
                'sync_configuration_id' => $configuration->id,
                'field_binding_id' => $binding->id,
                'external_field_key' => 'field_y',
            ]);

            $revisionAfterRejectedRemove = SyncConfiguration::withoutWorkspaceScope()
                ->findOrFail($configuration->id)
                ->configuration_revision;

            $this->assertNotSame($revisionAfterConfirm, $revisionAfterRejectedRemove);
        }
    }
}
