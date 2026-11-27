<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetOverride;
use App\Models\AdobeProductTypeAttributeSetDefault;
use App\Models\Category;
use App\Models\ConnectorCategoryMapping;
use App\Models\Product;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Services\Sync\AdobeProductClassificationSnapshotService;
use App\Services\Sync\SyncPreviewConfigurationSnapshotBuilder;
use App\Services\Sync\SyncProductSelectionService;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

class AdobeProductClassificationSnapshotServiceTest extends TestCase
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
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import],
            [SyncDataDomain::Products, SyncSemanticOperation::Export],
        ]);
    }

    #[Test]
    public function preview_snapshot_scopes_classification_to_selected_products_and_freezes_revision(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
        $configuration = $this->createProductsSyncConfiguration($account);
        $category = $this->category($workspace, 'Strollers');
        $selected = $this->product($workspace, $category, 'SELECTED');
        $unselected = $this->product($workspace, $category, 'UNSELECTED');
        $defaultSet = $this->attributeSet($account, 9, 'Default');
        $overrideSet = $this->attributeSet($account, 10, 'Override');

        ConnectorCategoryMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'category_id' => $category->id,
            'external_category_id' => '7',
        ]);
        AdobeProductTypeAttributeSetDefault::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_type_id' => $selected->product_type_id,
            'adobe_product_attribute_set_id' => $defaultSet->id,
        ]);

        $configuration = app(SyncProductSelectionService::class)->replace(
            $account,
            $configuration->id,
            [$selected->id],
        );

        $builder = app(SyncPreviewConfigurationSnapshotBuilder::class);
        $snapshotService = app(AdobeProductClassificationSnapshotService::class);

        $first = $builder->build($configuration, SyncSemanticOperation::Export);

        $this->assertCount(1, $first['adobe_product_classifications']);
        $this->assertSame((string) $selected->id, $first['adobe_product_classifications'][0]['product_id']);
        $this->assertSame(9, $first['adobe_product_classifications'][0]['provider_attribute_set_id']);
        $this->assertSame('product_type_default', $first['adobe_product_classifications'][0]['attribute_set_source']);
        $this->assertSame(
            $snapshotService->revisionFromPayload($first['adobe_product_classifications']),
            $first['adobe_product_classification_revision'],
        );
        $this->assertSame(
            $first['adobe_product_classifications'][0],
            $snapshotService->classificationForProduct($selected->id, $first),
        );
        $this->assertNull($snapshotService->classificationForProduct($unselected->id, $first));

        AdobeProductAttributeSetOverride::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $unselected->id,
            'adobe_product_attribute_set_id' => $overrideSet->id,
        ]);

        $afterUnselectedChange = $builder->build($configuration, SyncSemanticOperation::Export);

        $this->assertSame(
            $first['adobe_product_classification_revision'],
            $afterUnselectedChange['adobe_product_classification_revision'],
        );

        AdobeProductAttributeSetOverride::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $selected->id,
            'adobe_product_attribute_set_id' => $overrideSet->id,
        ]);

        $afterSelectedChange = $builder->build($configuration, SyncSemanticOperation::Export);

        $this->assertNotSame(
            $first['adobe_product_classification_revision'],
            $afterSelectedChange['adobe_product_classification_revision'],
        );
        $this->assertSame(10, $afterSelectedChange['adobe_product_classifications'][0]['provider_attribute_set_id']);
        $this->assertSame('product_override', $afterSelectedChange['adobe_product_classifications'][0]['attribute_set_source']);
    }

    #[Test]
    public function adobe_import_snapshot_omits_export_classification_keys(): void
    {
        $account = $this->createConnectorAccount(null, ['auth_profile' => 'test_sync_support']);
        $configuration = $this->createProductsSyncConfiguration($account);

        $snapshot = app(SyncPreviewConfigurationSnapshotBuilder::class)->build(
            $configuration,
            SyncSemanticOperation::Import,
        );

        $this->assertArrayNotHasKey('adobe_product_classifications', $snapshot);
        $this->assertArrayNotHasKey('adobe_product_classification_revision', $snapshot);
    }

    private function product(
        Workspace $workspace,
        Category $category,
        string $sku,
    ): Product {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku.'-'.Str::random(6),
            'name' => $sku,
            'category_id' => $category->id,
            'is_active' => true,
        ]);
    }

    private function category(Workspace $workspace, string $name): Category
    {
        return Category::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
        ]);
    }

    private function attributeSet($account, int $providerId, string $name): AdobeProductAttributeSet
    {
        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);

        return AdobeProductAttributeSet::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_set_id' => $providerId,
            'name' => $name,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);
    }
}
