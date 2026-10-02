<?php

namespace Tests\Feature\Sync;

use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductCategoryOverride;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Services\Sync\AdobeProductClassificationMutationService;
use App\Support\Sync\Exceptions\AdobeProductClassificationException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class AdobeProductClassificationMutationServiceTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    private AdobeProductClassificationMutationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $this->service = app(AdobeProductClassificationMutationService::class);
    }

    #[Test]
    public function authorized_user_can_set_replace_and_reset_classification_state(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions(
            $workspace,
            $actor,
            [WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS],
        );

        $productType = $this->defaultProductType($workspace);
        $product = $this->product($workspace);
        $setA = $this->attributeSet($account, 9, 'Default');
        $setB = $this->attributeSet($account, 10, 'Strollers');

        $default = $this->service->setProductTypeAttributeSetDefault(
            $actor,
            $workspace,
            $account,
            $productType,
            $setA,
        );
        $updatedDefault = $this->service->setProductTypeAttributeSetDefault(
            $actor,
            $workspace,
            $account,
            $productType,
            $setB,
        );

        $this->assertSame($default->id, $updatedDefault->id);
        $this->assertSame($setB->id, $updatedDefault->adobe_product_attribute_set_id);
        $this->assertDatabaseCount('adobe_product_type_attribute_set_defaults', 1);

        $override = $this->service->setProductAttributeSetOverride(
            $actor,
            $workspace,
            $account,
            $product,
            $setA,
        );
        $this->assertSame($setA->id, $override->adobe_product_attribute_set_id);

        $categories = $this->service->replaceProductCategoryOverrides(
            $actor,
            $workspace,
            $account,
            $product,
            ['8', ' 6 ', '8'],
        );

        $this->assertSame(['6', '8'], array_map(
            static fn (AdobeProductCategoryOverride $row): string => $row->external_category_id,
            $categories,
        ));

        $this->service->resetProductAttributeSetOverride($actor, $workspace, $account, $product);
        $this->service->resetProductCategoryOverrides($actor, $workspace, $account, $product);
        $this->service->resetProductTypeAttributeSetDefault($actor, $workspace, $account, $productType);

        $this->assertDatabaseCount('adobe_product_attribute_set_overrides', 0);
        $this->assertDatabaseCount('adobe_product_category_overrides', 0);
        $this->assertDatabaseCount('adobe_product_type_attribute_set_defaults', 0);
    }

    #[Test]
    public function mutation_requires_fresh_manage_sync_configurations_permission(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->grantExactWorkspacePermissions(
            $workspace,
            $actor,
            [WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS],
        );
        $productType = $this->defaultProductType($workspace);
        $set = $this->attributeSet($account, 9, 'Default');

        $this->service->setProductTypeAttributeSetDefault(
            $actor,
            $workspace,
            $account,
            $productType,
            $set,
        );

        $this->revokeAllWorkspaceRoles($membership);

        $this->expectException(AuthorizationException::class);

        try {
            $this->service->setProductTypeAttributeSetDefault(
                $actor,
                $workspace,
                $account,
                $productType,
                $set,
            );
        } finally {
            $this->assertDatabaseCount('adobe_product_type_attribute_set_defaults', 1);
        }
    }

    #[Test]
    public function foreign_account_attribute_set_is_rejected_before_write(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $otherAccount = $this->createConnectorAccount($workspace);
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions(
            $workspace,
            $actor,
            [WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS],
        );

        $productType = $this->defaultProductType($workspace);
        $foreignSet = $this->attributeSet($otherAccount, 11, 'Foreign');

        $this->expectException(AdobeProductClassificationException::class);

        try {
            $this->service->setProductTypeAttributeSetDefault(
                $actor,
                $workspace,
                $account,
                $productType,
                $foreignSet,
            );
        } finally {
            $this->assertDatabaseCount('adobe_product_type_attribute_set_defaults', 0);
        }
    }

    #[Test]
    public function missing_attribute_set_is_rejected_before_write(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions(
            $workspace,
            $actor,
            [WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS],
        );

        $product = $this->product($workspace);
        $missingSet = $this->attributeSet($account, 12, 'Missing');
        $missingSet->forceFill(['missing_since' => now()])->save();

        $this->expectException(AdobeProductClassificationException::class);

        try {
            $this->service->setProductAttributeSetOverride(
                $actor,
                $workspace,
                $account,
                $product,
                $missingSet,
            );
        } finally {
            $this->assertDatabaseCount('adobe_product_attribute_set_overrides', 0);
        }
    }

    #[Test]
    public function empty_category_override_is_rejected_without_erasing_existing_state(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions(
            $workspace,
            $actor,
            [WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS],
        );
        $product = $this->product($workspace);

        $this->service->replaceProductCategoryOverrides(
            $actor,
            $workspace,
            $account,
            $product,
            ['6'],
        );

        $this->expectException(AdobeProductClassificationException::class);

        try {
            $this->service->replaceProductCategoryOverrides(
                $actor,
                $workspace,
                $account,
                $product,
                [' ', ''],
            );
        } finally {
            $this->assertDatabaseHas('adobe_product_category_overrides', [
                'workspace_id' => $workspace->id,
                'connector_account_id' => $account->id,
                'product_id' => $product->id,
                'external_category_id' => '6',
            ]);
        }
    }

    #[Test]
    public function foreign_workspace_product_is_rejected_without_write(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign', 'is_default' => false]);
        $foreignProduct = $this->product($otherWorkspace);
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions(
            $workspace,
            $actor,
            [WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS],
        );

        $this->expectException(AdobeProductClassificationException::class);

        try {
            $this->service->replaceProductCategoryOverrides(
                $actor,
                $workspace,
                $account,
                $foreignProduct,
                ['6'],
            );
        } finally {
            $this->assertDatabaseCount('adobe_product_category_overrides', 0);
        }
    }

    private function defaultProductType(Workspace $workspace): ProductType
    {
        return ProductType::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->sole();
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
