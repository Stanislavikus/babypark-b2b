<?php

namespace Tests\Feature\Sync;

use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetOverride;
use App\Models\AdobeProductCategoryOverride;
use App\Models\AdobeProductTypeAttributeSetDefault;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class MagentoProductClassificationPersistenceTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function decision_b_tables_and_constraints_exist(): void
    {
        $this->assertTrue(Schema::hasTable('adobe_product_type_attribute_set_defaults'));
        $this->assertTrue(Schema::hasTable('adobe_product_attribute_set_overrides'));
        $this->assertTrue(Schema::hasTable('adobe_product_category_overrides'));

        $this->assertTrue(Schema::hasColumns('adobe_product_type_attribute_set_defaults', [
            'workspace_id',
            'connector_account_id',
            'product_type_id',
            'adobe_product_attribute_set_id',
        ]));
        $this->assertTrue(Schema::hasColumns('adobe_product_attribute_set_overrides', [
            'workspace_id',
            'connector_account_id',
            'product_id',
            'adobe_product_attribute_set_id',
        ]));
        $this->assertTrue(Schema::hasColumns('adobe_product_category_overrides', [
            'workspace_id',
            'connector_account_id',
            'product_id',
            'external_category_id',
        ]));
    }

    #[Test]
    public function valid_defaults_and_sparse_product_overrides_persist(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $attributeSet = $this->attributeSet($account, 9, 'Default');
        $productType = ProductType::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->sole();
        $product = $this->product($workspace);

        AdobeProductTypeAttributeSetDefault::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_type_id' => $productType->id,
            'adobe_product_attribute_set_id' => $attributeSet->id,
        ]);
        AdobeProductAttributeSetOverride::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'adobe_product_attribute_set_id' => $attributeSet->id,
        ]);
        foreach (['6', '8'] as $externalCategoryId) {
            AdobeProductCategoryOverride::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'connector_account_id' => $account->id,
                'product_id' => $product->id,
                'external_category_id' => $externalCategoryId,
            ]);
        }

        $this->assertDatabaseHas('adobe_product_type_attribute_set_defaults', [
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_type_id' => $productType->id,
            'adobe_product_attribute_set_id' => $attributeSet->id,
        ]);
        $this->assertDatabaseCount('adobe_product_category_overrides', 2);
    }

    #[Test]
    public function database_rejects_cross_account_attribute_set_reference(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $otherAccount = $this->createConnectorAccount($workspace);
        $foreignAttributeSet = $this->attributeSet($otherAccount, 10, 'Foreign');
        $productType = ProductType::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->sole();

        $this->expectException(QueryException::class);

        AdobeProductTypeAttributeSetDefault::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_type_id' => $productType->id,
            'adobe_product_attribute_set_id' => $foreignAttributeSet->id,
        ]);
    }

    #[Test]
    public function database_rejects_cross_workspace_product_reference(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign', 'is_default' => false]);
        $foreignProduct = $this->product($otherWorkspace);

        $this->expectException(QueryException::class);

        AdobeProductCategoryOverride::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $foreignProduct->id,
            'external_category_id' => '6',
        ]);
    }

    #[Test]
    public function database_rejects_duplicate_default_and_duplicate_category_override(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $attributeSet = $this->attributeSet($account, 9, 'Default');
        $productType = ProductType::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->sole();

        $payload = [
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_type_id' => $productType->id,
            'adobe_product_attribute_set_id' => $attributeSet->id,
        ];

        AdobeProductTypeAttributeSetDefault::withoutWorkspaceScope()->create($payload);

        $this->expectException(QueryException::class);
        AdobeProductTypeAttributeSetDefault::withoutWorkspaceScope()->create($payload);
    }

    private function product(Workspace $workspace): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CLASSIFY-'.Str::random(8),
            'name' => 'Classification fixture',
            'is_active' => true,
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
