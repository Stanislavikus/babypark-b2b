<?php

namespace Tests\Feature\Sync;

use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Enums\SyncDataDomain;
use App\Models\AdobeProductAttributeLineage;
use App\Models\AdobeProductAttributeOptionLineage;
use App\Models\AdobeProductAttributeSet;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Services\Connectors\AdobeRemoteCatalogProjectionService;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Services\Connectors\RemoteCatalogScanService;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogBrandProjectionResolver;
use App\Support\Connectors\RemoteCatalog\RemoteCatalogItemCandidate;
use App\Support\Connectors\RemoteCatalog\RemoteCatalogItemCategoryCandidate;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class RemoteCatalogProjectionV2Test extends TestCase
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
    public function provider_manufacturer_evidence_projects_without_claiming_canonical_brand(): void
    {
        $account = $this->createConnectorAccount();
        $resolver = app(AdobeRemoteCatalogBrandProjectionResolver::class);

        $this->assertNull($resolver->resolve($account));

        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);
        $lineage = AdobeProductAttributeLineage::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_id' => 83,
            'last_external_field_key' => 'manufacturer',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);
        AdobeProductAttributeOptionLineage::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'adobe_product_attribute_lineage_id' => $lineage->id,
            'provider_option_id' => '991',
            'default_label' => 'Carrello',
            'labels_by_store' => [],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);

        $context = $resolver->resolve($account);
        $this->assertNotNull($context);
        $this->assertSame('manufacturer', $context->fieldKey);
        $this->assertSame([
            'field_key' => 'manufacturer',
            'value' => '991',
            'label' => 'Carrello',
        ], $context->project(['manufacturer' => '991']));

    }

    #[Test]
    public function platform_created_product_is_projected_as_linked(): void
    {
        $account = $this->createConnectorAccount();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'LOCAL-PLATFORM-701',
            'name' => 'Local platform-created product',
            'is_active' => true,
        ]);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'REMOTE-PLATFORM-701',
            'trust_origin' => ExternalRecordLinkTrustOrigin::PlatformCreated->value,
            'external_record_discriminator' => '701',
            'established_by_workspace_user_id' => null,
            'established_at' => now(),
        ]);

        $scans = app(RemoteCatalogScanService::class);
        $scan = $scans->begin(
            $account,
            SyncDataDomain::Products,
            app(AdobeConnectorAccountTargetSnapshotResolver::class)->resolve($account)->toEnvelopeArray(),
            1,
        );
        $scans->append($scan, [
            new RemoteCatalogItemCandidate(
                remoteIdentifier: '701',
                sku: 'REMOTE-PLATFORM-701',
                name: 'Remote platform-created product',
                remoteType: 'simple',
                remoteStatus: '1',
                externalAttributeSetId: 10,
            ),
        ]);
        $scans->publish($scan);

        $projection = app(AdobeRemoteCatalogProjectionService::class);
        $summary = $projection->summary($account);
        $item = $projection->itemsQuery($account)->firstOrFail();

        $this->assertSame(1, $summary->linkedCount);
        $this->assertSame(0, $summary->remoteOnlyCount);
        $this->assertTrue((bool) $item->is_linked);
        $this->assertSame((int) $product->id, (int) $item->linked_product_id);
        $this->assertSame(1, $projection->filterItemsByLinkStatus($projection->itemsQuery($account), $account, 'linked')->count());
        $this->assertSame(0, $projection->remoteOnlyItemsQuery($account)->count());
    }

    #[Test]
    public function merchant_projection_returns_attribute_set_label_and_normalized_categories(): void
    {
        $account = $this->createConnectorAccount();
        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);

        AdobeProductAttributeSet::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_set_id' => 10,
            'name' => 'Attribute Set - Strollers',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);

        $scans = app(RemoteCatalogScanService::class);
        $scan = $scans->begin(
            $account,
            SyncDataDomain::Products,
            app(AdobeConnectorAccountTargetSnapshotResolver::class)->resolve($account)->toEnvelopeArray(),
            1,
        );
        $scans->append($scan, [
            new RemoteCatalogItemCandidate(
                remoteIdentifier: '501',
                sku: 'REMOTE-501',
                name: 'Remote stroller',
                remoteType: 'simple',
                remoteStatus: '1',
                externalAttributeSetId: 10,
                thumbnailLocator: '/r/e/remote.jpg',
                categories: [
                    new RemoteCatalogItemCategoryCandidate('6', 'BabyPark > Strollers', 1),
                    new RemoteCatalogItemCategoryCandidate('8', 'BabyPark > Sale', 2),
                ],
            ),
        ]);
        $scans->publish($scan);

        $item = app(AdobeRemoteCatalogProjectionService::class)
            ->itemsQuery($account)
            ->firstOrFail();

        $this->assertSame(10, $item->external_attribute_set_id);
        $this->assertSame('Attribute Set - Strollers', $item->attribute_set_name);
        $this->assertSame('/r/e/remote.jpg', $item->thumbnail_locator);
        $this->assertSame(
            ['BabyPark > Strollers', 'BabyPark > Sale'],
            $item->categories->pluck('category_path')->all(),
        );
        $this->assertSame(['6', '8'], $item->categories->pluck('external_category_id')->all());
    }
}
