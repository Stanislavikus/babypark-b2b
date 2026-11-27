<?php

namespace Tests\Integration\MySql;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\Product;
use App\Models\Workspace;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

class SyncProductSelectionMySqlTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-specific Product selection integrity probe.');
        }

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->configureSyncSupportProfile([[SyncDataDomain::Products, SyncSemanticOperation::Import]]);
    }

    public function test_composite_foreign_keys_reject_cross_workspace_selection_rows(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);

        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign '.Str::random(6),
            'is_default' => false,
        ]);
        $foreignProduct = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'MYSQL-FOREIGN-'.Str::random(8),
            'name' => 'Foreign Product',
            'is_active' => true,
        ]);

        $this->assertRawSelectionInsertFails(
            workspaceId: $account->workspace_id,
            configurationId: $configuration->id,
            productId: $foreignProduct->id,
        );

        $this->assertRawSelectionInsertFails(
            workspaceId: $foreignWorkspace->id,
            configurationId: $configuration->id,
            productId: $foreignProduct->id,
        );
    }

    private function assertRawSelectionInsertFails(string $workspaceId, string $configurationId, int $productId): void
    {
        try {
            DB::table('sync_configuration_product_selections')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'sync_configuration_id' => $configurationId,
                'product_id' => $productId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected composite workspace foreign key to reject invalid selection membership.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
