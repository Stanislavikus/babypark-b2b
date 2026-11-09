<?php

namespace Tests\Unit\CanonicalCoverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CrossPlatformSynthesisReconciliationTest extends TestCase
{
    private const SYNTHESIS = 'docs/data/cross_platform_product_field_synthesis.csv';

    #[Test]
    public function reconciled_synthesis_has_unique_concepts_and_expected_size(): void
    {
        $rows = $this->readCsv($this->root().'/'.self::SYNTHESIS);
        $keys = array_column($rows, 'concept_key');

        $this->assertCount(86, $rows);
        $this->assertCount(86, array_unique($keys));
    }

    #[Test]
    public function image_collection_keeps_media_semantic_ownership(): void
    {
        $row = $this->synthesis()['images'];

        $this->assertSame('Media_domain', $row['platform_owner_candidate']);
        $this->assertSame('media_domain', $row['representation_candidate']);
        $this->assertSame('KEEP_DOMAIN_CANONICAL', $row['canonical_decision']);
        $this->assertStringContainsString('products.images JSON', $row['rationale']);

        foreach ([
            'docs/data/canonical-coverage/adobe.csv' => 'media_gallery_entries',
            'docs/data/canonical-coverage/google-merchant.csv' => 'additionalImageLinks',
            'docs/data/canonical-coverage/bigcommerce.csv' => 'images',
            'docs/data/canonical-coverage/amazon.csv' => 'other_product_image_locator_1',
            'docs/data/canonical-coverage/shopify.csv' => 'Product.media',
        ] as $file => $externalKey) {
            $provider = $this->findCoverage($file, $externalKey);
            $this->assertSame('Media', $provider['owner_candidate']);
        }
    }

    #[Test]
    public function google_multipack_is_not_used_as_generic_package_quantity_equivalence(): void
    {
        $row = $this->synthesis()['package_quantity'];
        $multipack = $this->findCoverage('docs/data/canonical-coverage/google-merchant.csv', 'multipack');

        $this->assertSame('KEEP_PLATFORM_CANONICAL', $row['canonical_decision']);
        $this->assertStringContainsString('not generic package_quantity evidence', $row['google_evidence']);
        $this->assertSame('DEFER_DECISION', $multipack['disposition']);
        $this->assertSame('ProductOrPackaging', $multipack['owner_candidate']);
        $this->assertSame('identical_product_multipack_quantity_candidate', $multipack['representation_candidate']);
    }

    #[Test]
    public function google_vertical_aggregates_preserve_category_ownership_and_exclude_other_domains(): void
    {
        $synthesis = $this->synthesis();
        $property = $synthesis['google_property_fields'];
        $vehicle = $synthesis['google_vehicle_fields'];

        $this->assertSame('PropertyVertical', $property['platform_owner_candidate']);
        $this->assertSame('vertical_scoped_category_attribute', $property['representation_candidate']);
        $this->assertStringContainsString('productFee excluded', $property['google_evidence']);
        $this->assertSame('VehicleVertical', $vehicle['platform_owner_candidate']);
        $this->assertSame('vertical_scoped_category_attribute', $vehicle['representation_candidate']);
        $this->assertStringContainsString('Pricing and Compliance rows excluded', $vehicle['google_evidence']);

        $productFee = $this->findCoverage('docs/data/canonical-coverage/google-merchant.csv', 'productFee');
        $vehicleMsrp = $this->findCoverage('docs/data/canonical-coverage/google-merchant.csv', 'vehicleMsrp');
        $co2 = $this->findCoverage('docs/data/canonical-coverage/google-merchant.csv', 'co2Emissions');
        $vin = $this->findCoverage('docs/data/canonical-coverage/google-merchant.csv', 'vin');

        $this->assertSame('Pricing', $productFee['owner_candidate']);
        $this->assertSame('Pricing', $vehicleMsrp['owner_candidate']);
        $this->assertSame('Compliance', $co2['owner_candidate']);
        $this->assertSame('VehicleVertical', $vin['owner_candidate']);
        $this->assertSame('CATEGORY_ATTRIBUTE', $vin['disposition']);
    }

    #[Test]
    public function bigcommerce_metrics_remain_external_derived_projections(): void
    {
        $row = $this->synthesis()['bigcommerce_metrics'];

        $this->assertSame('Connector', $row['platform_owner_candidate']);
        $this->assertSame('derived_projection', $row['representation_candidate']);
        $this->assertSame('KEEP_CHANNEL_CANONICAL_ONLY', $row['canonical_decision']);

        foreach (['view_count', 'reviews_count', 'total_sold'] as $key) {
            $provider = $this->findCoverage('docs/data/canonical-coverage/bigcommerce.csv', $key);
            $this->assertSame('DERIVED_PROJECTION', $provider['disposition']);
        }
    }

    #[Test]
    public function amazon_schema_taxonomy_and_suggested_asin_stay_distinct(): void
    {
        $synthesis = $this->synthesis();
        $productType = $synthesis['amazon_product_type'];
        $taxonomy = $synthesis['amazon_item_type_taxonomy'];
        $asin = $synthesis['amazon_asin'];

        $this->assertSame('ProviderSchemaDiscovery', $productType['platform_owner_candidate']);
        $this->assertSame('schema_applicability', $productType['representation_candidate']);
        $this->assertSame('ProductTypeDefinition.productType', $productType['amazon_evidence']);
        $this->assertSame('ConnectorTaxonomy', $taxonomy['platform_owner_candidate']);
        $this->assertSame('amazon_taxonomy_context', $taxonomy['representation_candidate']);
        $this->assertSame('item_type_keyword / item_type_name', $taxonomy['amazon_evidence']);
        $this->assertSame('ConnectorIdentityReference', $asin['platform_owner_candidate']);
        $this->assertSame('seller_suggested_asin_not_established_identity', $asin['representation_candidate']);
        $this->assertStringNotContainsString('Catalog identity', $asin['amazon_evidence']);

        $ptd = $this->findCoverage('docs/data/canonical-coverage/amazon.csv', 'productType');
        $itemType = $this->findCoverage('docs/data/canonical-coverage/amazon.csv', 'item_type_keyword');
        $suggestedAsin = $this->findCoverage('docs/data/canonical-coverage/amazon.csv', 'merchant_suggested_asin');

        $this->assertSame('APPLICABILITY_METADATA', $ptd['disposition']);
        $this->assertSame('ProviderSchemaDiscovery', $ptd['owner_candidate']);
        $this->assertSame('ConnectorTaxonomy', $itemType['owner_candidate']);
        $this->assertSame('amazon_taxonomy_context', $itemType['representation_candidate']);
        $this->assertSame('CHANNEL_SEMANTIC', $suggestedAsin['disposition']);
        $this->assertSame('seller_suggested_asin_not_established_identity', $suggestedAsin['representation_candidate']);
    }

    /** @return array<string, array<string, string>> */
    private function synthesis(): array
    {
        return array_column($this->readCsv($this->root().'/'.self::SYNTHESIS), null, 'concept_key');
    }

    /** @return array<string, string> */
    private function findCoverage(string $file, string $externalKey): array
    {
        foreach ($this->readCsv($this->root().'/'.$file) as $row) {
            if ($row['external_key'] === $externalKey) {
                return $row;
            }
        }

        $this->fail("Coverage row not found: {$file} :: {$externalKey}");
    }

    /** @return list<array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];
        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($values !== [null]) {
                $rows[] = array_combine($header, $values);
            }
        }
        fclose($handle);

        return $rows;
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
