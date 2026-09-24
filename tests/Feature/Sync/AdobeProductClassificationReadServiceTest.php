<?php

namespace Tests\Feature\Sync;

use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Enums\SyncDataDomain;
use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetOverride;
use App\Models\AdobeProductCategoryOverride;
use App\Models\AdobeProductTypeAttributeSetDefault;
use App\Models\Category;
use App\Models\ConnectorCategoryMapping;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Services\Connectors\RemoteCatalogScanService;
use App\Services\Sync\AdobeProductClassificationReadService;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\RemoteCatalog\RemoteCatalogItemCandidate;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class AdobeProductClassificationReadServiceTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    private AdobeProductClassificationReadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);

        $this->service = app(AdobeProductClassificationReadService::class);
    }

    #[Test]
    public function unlinked_product_uses_product_overrides_before_reusable_defaults(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $category = $this->category($workspace, 'Strollers');
        $product = $this->product($workspace, $category);
        $defaultSet = $this->attributeSet($account, 9, 'Default');
        $overrideSet = $this->attributeSet($account, 10, 'Override');

        ConnectorCategoryMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'category_id' => $category->id,
            'external_category_id' => '5',
        ]);
        AdobeProductTypeAttributeSetDefault::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_type_id' => $product->product_type_id,
            'adobe_product_attribute_set_id' => $defaultSet->id,
        ]);
        AdobeProductAttributeSetOverride::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'adobe_product_attribute_set_id' => $overrideSet->id,
        ]);
        foreach (['8', '6'] as $externalCategoryId) {
            AdobeProductCategoryOverride::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'connector_account_id' => $account->id,
                'product_id' => $product->id,
                'external_category_id' => $externalCategoryId,
            ]);
        }

        $result = $this->service->resolve($account, $product);

        $this->assertSame(['6', '8'], $result->externalCategoryIds);
        $this->assertSame('product_override', $result->categorySource);
        $this->assertSame(10, $result->providerAttributeSetId);
        $this->assertSame('product_override', $result->attributeSetSource);
        $this->assertFalse($result->hasTrustedRemoteSubject);
        $this->assertTrue($result->isReady());
    }

    #[Test]
    public function unlinked_product_falls_back_to_category_and_product_type_defaults(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $category = $this->category($workspace, 'Car Seats');
        $product = $this->product($workspace, $category);
        $defaultSet = $this->attributeSet($account, 9, 'Default');

        ConnectorCategoryMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'category_id' => $category->id,
            'external_category_id' => '7',
        ]);
        AdobeProductTypeAttributeSetDefault::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_type_id' => $product->product_type_id,
            'adobe_product_attribute_set_id' => $defaultSet->id,
        ]);

        $result = $this->service->resolve($account, $product);

        $this->assertSame(['7'], $result->externalCategoryIds);
        $this->assertSame('category_mapping', $result->categorySource);
        $this->assertSame(9, $result->providerAttributeSetId);
        $this->assertSame('product_type_default', $result->attributeSetSource);
        $this->assertTrue($result->isReady());
    }

    #[Test]
    public function trusted_parent_observed_attribute_set_outranks_preparation_intent(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $category = $this->category($workspace, 'Strollers');
        $product = $this->product($workspace, $category);
        $this->variant($product, 'PARENT-CHILD-A');
        $this->variant($product, 'PARENT-CHILD-B');
        $configuredSet = $this->attributeSet($account, 9, 'Configured');
        $observedSet = $this->attributeSet($account, 10, 'Observed');

        $this->configureDefaults($workspace, $account, $product, $category, $configuredSet);
        AdobeProductAttributeSetOverride::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'adobe_product_attribute_set_id' => $configuredSet->id,
        ]);

        $membershipId = $this->membershipId($workspace);
        $this->trustedParentLink($workspace, $account, $product, '501', $membershipId);
        $this->publishRemoteItems($account, [
            new RemoteCatalogItemCandidate(
                remoteIdentifier: '501',
                sku: 'CFG-501',
                name: 'Existing configurable',
                remoteType: 'configurable',
                remoteStatus: '1',
                externalAttributeSetId: 10,
            ),
        ]);

        $result = $this->service->resolve($account, $product);

        $this->assertSame(10, $result->providerAttributeSetId);
        $this->assertSame('observed_remote', $result->attributeSetSource);
        $this->assertTrue($result->hasTrustedRemoteSubject);
        $this->assertContains('product_override_differs_from_observed_remote', $result->advisories);
        $this->assertContains('product_type_default_differs_from_observed_remote', $result->advisories);
        $this->assertTrue($result->isReady());
    }

    #[Test]
    public function trusted_simple_variant_observed_attribute_set_is_product_structural_truth(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $category = $this->category($workspace, 'Strollers');
        $product = $this->product($workspace, $category);
        $variant = $this->variant($product, 'SIMPLE-501');
        $fallbackSet = $this->attributeSet($account, 9, 'Fallback');
        $this->attributeSet($account, 10, 'Observed');
        $this->configureDefaults($workspace, $account, $product, $category, $fallbackSet);

        $membershipId = $this->membershipId($workspace);
        $this->trustedVariantLink($workspace, $account, $variant, '501', $membershipId);
        $this->publishRemoteItems($account, [
            new RemoteCatalogItemCandidate(
                remoteIdentifier: '501',
                sku: 'SIMPLE-501',
                name: 'Existing simple',
                remoteType: 'simple',
                remoteStatus: '1',
                externalAttributeSetId: 10,
            ),
        ]);

        $result = $this->service->resolve($account, $product);

        $this->assertSame(10, $result->providerAttributeSetId);
        $this->assertSame('observed_remote', $result->attributeSetSource);
        $this->assertTrue($result->hasTrustedRemoteSubject);
        $this->assertTrue($result->isReady());
    }

    #[Test]
    public function several_trusted_variants_may_share_one_observed_product_level_set(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $category = $this->category($workspace, 'Strollers');
        $product = $this->product($workspace, $category);
        $variantA = $this->variant($product, 'SIMPLE-501');
        $variantB = $this->variant($product, 'SIMPLE-502');
        $fallbackSet = $this->attributeSet($account, 9, 'Fallback');
        $this->attributeSet($account, 10, 'Observed');
        $this->configureDefaults($workspace, $account, $product, $category, $fallbackSet);

        $membershipId = $this->membershipId($workspace);
        $this->trustedVariantLink($workspace, $account, $variantA, '501', $membershipId);
        $this->trustedVariantLink($workspace, $account, $variantB, '502', $membershipId);
        $this->publishRemoteItems($account, [
            new RemoteCatalogItemCandidate('501', 'SIMPLE-501', 'Child A', 'simple', '1', 10),
            new RemoteCatalogItemCandidate('502', 'SIMPLE-502', 'Child B', 'simple', '1', 10),
        ]);

        $result = $this->service->resolve($account, $product);

        $this->assertSame(10, $result->providerAttributeSetId);
        $this->assertSame('observed_remote', $result->attributeSetSource);
        $this->assertTrue($result->isReady());
    }

    #[Test]
    public function conflicting_trusted_variant_attribute_sets_block_instead_of_falling_back(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $category = $this->category($workspace, 'Strollers');
        $product = $this->product($workspace, $category);
        $variantA = $this->variant($product, 'SIMPLE-501');
        $variantB = $this->variant($product, 'SIMPLE-502');
        $fallbackSet = $this->attributeSet($account, 9, 'Fallback');
        $this->attributeSet($account, 10, 'Observed A');
        $this->attributeSet($account, 11, 'Observed B');
        $this->configureDefaults($workspace, $account, $product, $category, $fallbackSet);

        $membershipId = $this->membershipId($workspace);
        $this->trustedVariantLink($workspace, $account, $variantA, '501', $membershipId);
        $this->trustedVariantLink($workspace, $account, $variantB, '502', $membershipId);
        $this->publishRemoteItems($account, [
            new RemoteCatalogItemCandidate('501', 'SIMPLE-501', 'Child A', 'simple', '1', 10),
            new RemoteCatalogItemCandidate('502', 'SIMPLE-502', 'Child B', 'simple', '1', 11),
        ]);

        $result = $this->service->resolve($account, $product);

        $this->assertNull($result->providerAttributeSetId);
        $this->assertSame('unresolved', $result->attributeSetSource);
        $this->assertContains('trusted_remote_attribute_set_conflict', $result->blockers);
        $this->assertTrue($result->hasTrustedRemoteSubject);
        $this->assertFalse($result->isReady());
    }

    #[Test]
    public function trusted_subject_missing_from_current_remote_snapshot_blocks_fallback(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $category = $this->category($workspace, 'Strollers');
        $product = $this->product($workspace, $category);
        $variant = $this->variant($product, 'SIMPLE-501');
        $fallbackSet = $this->attributeSet($account, 9, 'Fallback');
        $this->configureDefaults($workspace, $account, $product, $category, $fallbackSet);

        $membershipId = $this->membershipId($workspace);
        $this->trustedVariantLink($workspace, $account, $variant, '501', $membershipId);
        $this->publishRemoteItems($account, []);

        $result = $this->service->resolve($account, $product);

        $this->assertNull($result->providerAttributeSetId);
        $this->assertContains('trusted_remote_attribute_set_unresolved', $result->blockers);
        $this->assertTrue($result->hasTrustedRemoteSubject);
        $this->assertFalse($result->isReady());
    }

    #[Test]
    public function unresolved_defaults_report_readiness_blockers(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $product = $this->product($workspace);

        $result = $this->service->resolve($account, $product);

        $this->assertSame([], $result->externalCategoryIds);
        $this->assertNull($result->providerAttributeSetId);
        $this->assertContains('category_unresolved', $result->blockers);
        $this->assertContains('attribute_set_unresolved', $result->blockers);
        $this->assertFalse($result->isReady());
    }

    private function configureDefaults(
        Workspace $workspace,
        $account,
        Product $product,
        Category $category,
        AdobeProductAttributeSet $attributeSet,
    ): void {
        ConnectorCategoryMapping::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'category_id' => $category->id,
            'external_category_id' => '7',
        ]);
        AdobeProductTypeAttributeSetDefault::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_type_id' => $product->product_type_id,
            'adobe_product_attribute_set_id' => $attributeSet->id,
        ]);
    }

    private function product(Workspace $workspace, ?Category $category = null): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CLASSIFY-'.Str::random(8),
            'name' => 'Classification fixture',
            'category_id' => $category?->id,
            'is_active' => true,
        ]);
    }

    private function variant(Product $product, string $sku): ProductVariant
    {
        return ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $product->workspace_id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
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

    private function membershipId(Workspace $workspace): string
    {
        $actor = User::factory()->create(['is_active' => true]);

        return $this->makeWorkspaceMembership($workspace, $actor, true)->id;
    }

    private function trustedParentLink(
        Workspace $workspace,
        $account,
        Product $product,
        string $remoteId,
        string $membershipId,
    ): void {
        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'external_identifier' => 'CFG-'.$remoteId,
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => $remoteId,
            'established_by_workspace_user_id' => $membershipId,
            'established_at' => now(),
        ]);
    }

    private function trustedVariantLink(
        Workspace $workspace,
        $account,
        ProductVariant $variant,
        string $remoteId,
        string $membershipId,
    ): void {
        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => null,
            'product_variant_id' => $variant->id,
            'external_identifier' => $variant->sku,
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => $remoteId,
            'established_by_workspace_user_id' => $membershipId,
            'established_at' => now(),
        ]);
    }

    /**
     * @param  list<RemoteCatalogItemCandidate>  $items
     */
    private function publishRemoteItems($account, array $items): void
    {
        $scans = app(RemoteCatalogScanService::class);
        $scan = $scans->begin(
            $account,
            SyncDataDomain::Products,
            app(AdobeConnectorAccountTargetSnapshotResolver::class)->resolve($account)->toEnvelopeArray(),
            count($items),
        );

        if ($items !== []) {
            $scans->append($scan, $items);
        }

        $scans->publish($scan);
    }
}
