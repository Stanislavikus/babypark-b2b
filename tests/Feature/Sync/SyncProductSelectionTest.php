<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\Product;
use App\Models\SyncConfigurationProductSelection;
use App\Models\Workspace;
use App\Services\Sync\Exceptions\InvalidSyncProductSelectionException;
use App\Services\Sync\SyncProductSelectionService;
use App\Support\Sync\SyncProductSelectionDescriptor;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

class SyncProductSelectionTest extends TestCase
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
        $this->configureSyncSupportProfile([[SyncDataDomain::Products, SyncSemanticOperation::Import]]);
    }

    #[Test]
    public function explicit_product_membership_is_normalized_and_part_of_configuration_revision(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $productA = $this->createProduct($account->workspace_id, 'SEL-A');
        $productB = $this->createProduct($account->workspace_id, 'SEL-B');
        $service = app(SyncProductSelectionService::class);

        $this->assertSame([], $service->selectedProductIds($configuration));
        $this->assertSame(
            SyncProductSelectionDescriptor::empty()->productIdsHash,
            SyncProductSelectionDescriptor::empty()->toRevisionArray()['product_ids_hash'],
        );

        $initialRevision = $configuration->configuration_revision;
        $selected = $service->replace($account, $configuration->id, [$productB->id, $productA->id, $productA->id]);

        $this->assertSame([$productA->id, $productB->id], $service->selectedProductIds($selected));
        $this->assertNotSame($initialRevision, $selected->configuration_revision);
        $selectedRevision = $selected->configuration_revision;

        $sameSet = $service->replace($account, $configuration->id, [$productA->id, $productB->id]);
        $this->assertSame($selectedRevision, $sameSet->configuration_revision);
        $this->assertSame(2, SyncConfigurationProductSelection::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->count());

        $removed = $service->remove($account, $configuration->id, [$productB->id]);
        $this->assertSame([$productA->id], $service->selectedProductIds($removed));
        $this->assertNotSame($selectedRevision, $removed->configuration_revision);
    }

    #[Test]
    public function selection_service_rejects_products_from_another_workspace(): void
    {
        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign '.Str::random(6),
            'is_default' => false,
        ]);
        $foreignProduct = $this->createProduct($foreignWorkspace->id, 'FOREIGN-SEL');

        $this->expectException(InvalidSyncProductSelectionException::class);

        app(SyncProductSelectionService::class)->replace(
            $account,
            $configuration->id,
            [$foreignProduct->id],
        );
    }

    private function createProduct(string $workspaceId, string $skuPrefix): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceId,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $skuPrefix.'-'.Str::random(8),
            'name' => $skuPrefix,
            'is_active' => true,
        ]);
    }
}
