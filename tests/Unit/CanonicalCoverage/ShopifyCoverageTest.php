<?php

namespace Tests\Unit\CanonicalCoverage;

use App\Support\CanonicalCoverage\AdobeCommerceCoverage;
use App\Support\CanonicalCoverage\AmazonCoverage;
use App\Support\CanonicalCoverage\BigCommerceCoverage;
use App\Support\CanonicalCoverage\GoogleMerchantCoverage;
use App\Support\CanonicalCoverage\ShopifyCoverage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ShopifyCoverageTest extends TestCase
{
    #[Test]
    public function committed_shopify_slice_has_exact_frozen_denominator(): void
    {
        $metrics = (new ShopifyCoverage)->validate(dirname(__DIR__, 3));

        $this->assertSame(738, $metrics['master_rows']);
        $this->assertSame(926, $metrics['structured_rows']);
        $this->assertSame(106, $metrics['alias_rows']);
        $this->assertSame(8556, $metrics['taxonomy_rows']);
        $this->assertSame(36, $metrics['freshness_rows']);
        $this->assertSame(14, $metrics['version_rows']);
        $this->assertSame(10376, $metrics['coverage_rows']);
        $this->assertSame(10314, $metrics['concepts']);
        $this->assertSame(2, $metrics['disagreements']);
        $this->assertSame(1.0, $metrics['coverage_ratio']);
        $this->assertSame(0, $metrics['silent_drop_count']);
    }

    #[Test]
    public function taxonomy_freshness_and_version_facts_cannot_be_promoted_to_product_truth(): void
    {
        $rows = $this->coverage();
        $taxonomy = array_filter($rows, fn ($row) => $row['source_file'] === ShopifyCoverage::TAXONOMY);
        $freshness = array_filter($rows, fn ($row) => $row['source_file'] === ShopifyCoverage::FRESHNESS);
        $versions = array_filter($rows, fn ($row) => $row['source_file'] === ShopifyCoverage::VERSIONS);

        $this->assertCount(8556, $taxonomy);
        foreach ($taxonomy as $row) {
            $this->assertSame('CATEGORY_ATTRIBUTE', $row['disposition']);
            $this->assertSame('ConnectorTaxonomy', $row['owner_candidate']);
            $this->assertSame('not_applicable', $row['applicability_key']);
        }
        $this->assertCount(36, $freshness);
        foreach ($freshness as $row) {
            $this->assertSame('TRANSPORT_MECHANIC', $row['disposition']);
            $this->assertSame('event_hint_requires_authoritative_reread', $row['read_semantics']);
        }
        $this->assertCount(14, $versions);
        foreach ($versions as $row) {
            $this->assertSame('APPLICABILITY_METADATA', $row['disposition']);
            $this->assertSame('ConnectorSchema', $row['owner_candidate']);
        }
    }

    #[Test]
    public function reusable_product_candidates_are_narrow_and_vendor_is_channel_owned(): void
    {
        $master = $this->masterRows();
        foreach (['Product.title', 'Product.descriptionHtml', 'Product.productType', 'Product.status', 'Product.tags', 'ProductVariant.sku', 'ProductVariant.barcode'] as $key) {
            $this->assertSame('REUSABLE_SEMANTIC', $master[$key]['disposition'], $key);
        }
        $this->assertSame('CHANNEL_SEMANTIC', $master['Product.vendor']['disposition']);
        $this->assertSame('Connector', $master['Product.vendor']['owner_candidate']);
        $this->assertSame('shopify_vendor_label', $master['Product.vendor']['representation_candidate']);
        $this->assertNotSame($master['Product.vendor']['concept_key'], $master['Product.title']['concept_key']);
    }

    #[Test]
    public function product_csv_is_bulk_transport_not_a_second_canonical_field_set(): void
    {
        $csvRows = array_values(array_filter($this->coverage(), fn ($row) => $row['source_file'] === ShopifyCoverage::MASTER && $row['source_surface'] === 'product_csv'));
        $this->assertCount(41, $csvRows);
        foreach ($csvRows as $row) {
            $this->assertSame('TRANSPORT_MECHANIC', $row['disposition']);
            $this->assertSame('ConnectorTransport', $row['owner_candidate']);
            $this->assertSame('bulk_csv_field_representation', $row['representation_candidate']);
        }
    }

    #[Test]
    public function domain_semantic_values_do_not_become_product_field_candidates(): void
    {
        $master = $this->masterRows();
        $expected = [
            'Collection.title' => 'Category',
            'Collection.descriptionHtml' => 'Category',
            'Metafield.value' => 'DynamicField',
            'Media.alt' => 'Media',
            'Translation.value' => 'Localization',
            'SellingPlan.name' => 'PurchaseOptions',
            'GiftCardProductSetInput.title' => 'GiftCard',
        ];
        foreach ($expected as $key => $owner) {
            $this->assertSame('DOMAIN_CAPABILITY', $master[$key]['disposition'], $key);
            $this->assertSame($owner, $master[$key]['owner_candidate'], $key);
        }
    }

    #[Test]
    public function nested_ids_remain_structure_members_and_transport_ids_remain_transport(): void
    {
        $rows = $this->coverage();
        $structuredIds = array_values(array_filter($rows, fn ($row) => $row['source_file'] === ShopifyCoverage::STRUCTURED && (str_ends_with(strtolower($row['external_key']), '.id') || str_ends_with(strtolower($row['external_key']), '.uid'))));
        $this->assertNotEmpty($structuredIds);
        foreach ($structuredIds as $row) {
            $this->assertSame('STRUCTURE_MEMBER', $row['disposition']);
        }

        $master = $this->masterRows();
        $this->assertSame('EXTERNAL_IDENTITY', $master['Product.id']['disposition']);
        $this->assertSame('external_record_identity', $master['Product.id']['representation_candidate']);
        $this->assertSame('TRANSPORT_MECHANIC', $master['ProductSetOperation.id']['disposition']);
        $this->assertSame('async_transport_identity', $master['ProductSetOperation.id']['representation_candidate']);
        $this->assertSame('TRANSPORT_MECHANIC', $master['WebhookSubscription.id']['disposition']);
        $this->assertSame('connector_subscription_identity', $master['WebhookSubscription.id']['representation_candidate']);
        $this->assertSame('EXTERNAL_IDENTITY', $master['TaxonomyCategory.id']['disposition']);
        $this->assertSame('taxonomy_object_identity', $master['TaxonomyCategory.id']['representation_candidate']);
    }

    #[Test]
    public function aliases_have_real_targets_without_forcing_raw_equality(): void
    {
        $rows = $this->coverage();
        $ids = array_fill_keys(array_column($rows, 'coverage_id'), true);
        $aliases = array_values(array_filter($rows, fn ($row) => $row['source_file'] === ShopifyCoverage::ALIASES));
        $this->assertCount(106, $aliases);
        foreach ($aliases as $row) {
            $this->assertSame('ALIAS_REPRESENTATION', $row['disposition']);
            $this->assertArrayHasKey($row['alias_of_coverage_id'], $ids);
            $this->assertStringContainsString('identity_rule=', $row['source_context_key']);
        }
    }

    #[Test]
    public function short_title_and_unit_pricing_remain_explicitly_deferred(): void
    {
        $master = $this->masterRows();
        foreach (['ProductVariant.showUnitPrice', 'ProductVariant.unitPrice', 'ProductVariant.unitPriceMeasurement'] as $key) {
            $this->assertSame('DEFER_DECISION', $master[$key]['disposition']);
            $this->assertSame('DEFERRED_REVIEW', $master[$key]['review_status']);
            $this->assertStringContainsString('queue:shopify_unit_pricing_ownership', $master[$key]['decision_reference']);
        }
        $subtitle = $master['StandardMetafieldDefinition.descriptors.subtitle'];
        $this->assertSame('DEFER_DECISION', $subtitle['disposition']);
        $this->assertSame('FieldDefinitionOrContent', $subtitle['owner_candidate']);
        $this->assertStringContainsString('queue:shopify_short_title_ownership', $subtitle['decision_reference']);
    }

    #[Test]
    public function supporting_summary_files_are_not_double_counted_as_denominator_sources(): void
    {
        $manifest = $this->readCsv(dirname(__DIR__, 3).'/'.ShopifyCoverage::MANIFEST);
        $shopify = array_values(array_filter($manifest, fn ($row) => $row['platform'] === 'shopify'));
        $this->assertCount(6, $shopify);
        $files = array_column($shopify, 'source_file');
        foreach ([ShopifyCoverage::CLUSTERS, ShopifyCoverage::SOURCES, ShopifyCoverage::STANDARD_METAFIELDS] as $supporting) {
            $this->assertNotContains($supporting, $files);
        }
    }

    #[Test]
    public function all_five_provider_generators_round_trip_byte_identically(): void
    {
        $root = dirname(__DIR__, 3);
        $files = [
            ShopifyCoverage::MANIFEST,
            BigCommerceCoverage::COVERAGE, BigCommerceCoverage::CONCEPTS, BigCommerceCoverage::DISAGREEMENTS,
            AdobeCommerceCoverage::COVERAGE, AdobeCommerceCoverage::CONCEPTS, AdobeCommerceCoverage::DISAGREEMENTS,
            GoogleMerchantCoverage::COVERAGE, GoogleMerchantCoverage::CONCEPTS, GoogleMerchantCoverage::DISAGREEMENTS,
            AmazonCoverage::COVERAGE, AmazonCoverage::CONCEPTS, AmazonCoverage::DISAGREEMENTS,
            ShopifyCoverage::COVERAGE, ShopifyCoverage::CONCEPTS, ShopifyCoverage::DISAGREEMENTS,
        ];
        $before = $this->hashes($root, $files);

        (new BigCommerceCoverage)->generate($root);
        (new AdobeCommerceCoverage)->generate($root);
        (new GoogleMerchantCoverage)->generate($root);
        (new AmazonCoverage)->generate($root);
        (new ShopifyCoverage)->generate($root);
        (new BigCommerceCoverage)->validate($root);
        (new AdobeCommerceCoverage)->validate($root);
        (new GoogleMerchantCoverage)->validate($root);
        (new AmazonCoverage)->validate($root);
        (new ShopifyCoverage)->validate($root);

        $this->assertSame($before, $this->hashes($root, $files));
    }

    private function masterRows(): array
    {
        return array_column(array_filter($this->coverage(), fn ($row) => $row['source_file'] === ShopifyCoverage::MASTER), null, 'external_key');
    }

    private function coverage(): array
    {
        return $this->readCsv(dirname(__DIR__, 3).'/'.ShopifyCoverage::COVERAGE);
    }

    private function hashes(string $root, array $files): array
    {
        return array_map(fn ($file) => hash_file('sha256', "$root/$file"), $files);
    }

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
}
