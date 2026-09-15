<?php

namespace Tests\Unit\CanonicalCoverage;

use App\Support\CanonicalCoverage\AdobeCommerceCoverage;
use App\Support\CanonicalCoverage\AmazonCoverage;
use App\Support\CanonicalCoverage\BigCommerceCoverage;
use App\Support\CanonicalCoverage\GoogleMerchantCoverage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AmazonCoverageTest extends TestCase
{
    #[Test]
    public function committed_amazon_slice_is_complete_and_frozen(): void
    {
        $root = dirname(__DIR__, 3);
        $m = (new AmazonCoverage)->validate($root);
        $this->assertSame(23, $m['ptd_meta_model_rows']);
        $this->assertSame(78, $m['luggage_ptd_rows']);
        $this->assertSame(101, $m['coverage_rows']);
        $this->assertSame(1.0, $m['coverage_ratio']);
        $this->assertSame(0, $m['silent_drop_count']);
        $this->assertSame(AmazonCoverage::META_HASH, hash_file('sha256', "$root/".AmazonCoverage::META));
        $this->assertSame(AmazonCoverage::LUGGAGE_HASH, hash_file('sha256', "$root/".AmazonCoverage::LUGGAGE));
    }

    #[Test]
    public function meta_model_remains_schema_metadata_and_schema_links_are_transport(): void
    {
        $rows = array_filter($this->rows(), fn ($r) => $r['source_file'] === AmazonCoverage::META);
        $this->assertCount(23, $rows);
        foreach ($rows as $row) {
            $this->assertNotSame('ProductData', $row['owner_candidate']);
            $this->assertNotSame('REUSABLE_SEMANTIC', $row['disposition']);
            $this->assertSame('schema_discovery_metadata', $row['read_semantics']);
            $this->assertSame('not_product_write_capability', $row['write_semantics']);
            if ($row['source_object_family'] === 'SchemaLink') {
                $this->assertSame('TRANSPORT_MECHANIC', $row['disposition']);
            }
        }
    }

    #[Test]
    public function luggage_context_and_capability_claims_are_conservative(): void
    {
        foreach ($this->rows() as $row) {
            $this->assertSame('not_applicable', $row['applicability_key']);
        }
        foreach ($this->luggageRows() as $row) {
            foreach (['product_type=LUGGAGE', 'marketplace=ATVPDKIKX0DER', 'requirements=LISTING', 'property_group=', 'parentage=NONE example', 'schema_version_token=U8L4z4Ud95N16tZlR7rsmbQ=='] as $context) {
                $this->assertStringContainsString($context, $row['source_context_key']);
            }
            $this->assertSame('ptd_schema_presence_not_live_listing_read', $row['read_semantics']);
            $this->assertSame('ptd_conditioned_write_not_unconditional', $row['write_semantics']);
            $this->assertStringNotContainsString('PRODUCT_ONLY', $row['source_context_key']);
            $this->assertStringNotContainsString('OFFER_ONLY', $row['source_context_key']);
        }
    }

    #[Test]
    public function condition_type_and_listing_note_have_distinct_semantics(): void
    {
        $rows = $this->luggageRows();
        $type = $rows['condition_type'];
        $note = $rows['condition_note'];

        $this->assertSame('REUSABLE_SEMANTIC', $type['disposition']);
        $this->assertSame('ProductVariantData', $type['owner_candidate']);
        $this->assertSame('product_condition_enum', $type['representation_candidate']);
        $this->assertSame('CHANNEL_SEMANTIC', $note['disposition']);
        $this->assertSame('ListingCondition', $note['owner_candidate']);
        $this->assertSame('amazon_listing_condition_note', $note['representation_candidate']);
        $this->assertNotSame($type['concept_key'], $note['concept_key']);
        $this->assertNotSame($type['representation_candidate'], $note['representation_candidate']);
        $this->assertNotSame('ProductLifecycle', $type['owner_candidate']);
        $this->assertNotSame('ProductLifecycle', $note['owner_candidate']);
    }

    #[Test]
    public function luggage_specific_attributes_remain_product_type_scoped(): void
    {
        $rows = $this->luggageRows();
        foreach (['department', 'outer', 'fabric_type', 'lining_description', 'number_of_wheels', 'wheel', 'size_map'] as $key) {
            $this->assertSame('CATEGORY_ATTRIBUTE', $rows[$key]['disposition']);
            $this->assertSame('ProductTypeAttribute', $rows[$key]['owner_candidate']);
            $this->assertSame('luggage_ptd_scoped_attribute', $rows[$key]['representation_candidate']);
            $this->assertNotSame('REUSABLE_SEMANTIC', $rows[$key]['disposition']);
            $this->assertNotSame('ProductData', $rows[$key]['owner_candidate']);
        }
    }

    #[Test]
    public function identity_taxonomy_offer_and_deferred_boundaries_are_explicit(): void
    {
        $r = $this->luggageRows();
        foreach (['item_type_keyword', 'item_type_name'] as $key) {
            $this->assertSame('ConnectorTaxonomy', $r[$key]['owner_candidate']);
        }
        $this->assertSame('REUSABLE_SEMANTIC', $r['externally_assigned_product_identifier']['disposition']);
        $this->assertSame('ProductVariantData', $r['externally_assigned_product_identifier']['owner_candidate']);
        $this->assertSame('ConnectorGovernance', $r['supplier_declared_has_product_identifier_exemption']['owner_candidate']);
        $this->assertSame('CHANNEL_SEMANTIC', $r['merchant_suggested_asin']['disposition']);
        $this->assertSame('Pricing', $r['purchasable_offer']['owner_candidate']);
        $this->assertSame('Availability', $r['fulfillment_channel_availability']['owner_candidate']);
        $this->assertSame('Availability', $r['merchant_release_date']['owner_candidate']);
        foreach (['product_tax_code', 'max_order_quantity', 'gift_options'] as $key) {
            $this->assertSame('DEFER_DECISION', $r[$key]['disposition']);
            $this->assertSame('DEFERRED_REVIEW', $r[$key]['review_status']);
        }
    }

    #[Test]
    public function dimensions_media_compliance_and_variants_keep_distinct_owners(): void
    {
        $r = $this->luggageRows();
        $this->assertNotSame($r['item_dimensions']['concept_key'], $r['item_package_dimensions']['concept_key']);
        $this->assertSame('ProductData', $r['item_weight']['owner_candidate']);
        $this->assertSame('ComplianceMedia', $r['safety_data_sheet_url']['owner_candidate']);
        $this->assertSame('ComplianceMedia', $r['compliance_media']['owner_candidate']);
        foreach (['parentage_level', 'child_parent_sku_relationship', 'variation_theme'] as $key) {
            $this->assertSame('VariantComposition', $r[$key]['owner_candidate']);
        }
        $this->assertNotSame($r['main_offer_image_locator']['concept_key'], $r['main_product_image_locator']['concept_key']);
        $this->assertSame($r['other_product_image_locator_1']['concept_key'], $r['other_product_image_locator_8']['concept_key']);
        foreach (['style', 'warranty_description', 'bullet_point', 'special_feature'] as $key) {
            $this->assertStringStartsWith('amazon:', $r[$key]['concept_key']);
        }
        $this->assertNotSame($r['bullet_point']['concept_key'], $r['special_feature']['concept_key']);
    }

    #[Test]
    public function all_four_provider_generators_round_trip_byte_identically(): void
    {
        $root = dirname(__DIR__, 3);
        $files = [AmazonCoverage::MANIFEST, AmazonCoverage::COVERAGE, AmazonCoverage::CONCEPTS, AmazonCoverage::DISAGREEMENTS,
            GoogleMerchantCoverage::COVERAGE, GoogleMerchantCoverage::CONCEPTS, GoogleMerchantCoverage::DISAGREEMENTS,
            AdobeCommerceCoverage::COVERAGE, AdobeCommerceCoverage::CONCEPTS, AdobeCommerceCoverage::DISAGREEMENTS,
            BigCommerceCoverage::COVERAGE, BigCommerceCoverage::CONCEPTS, BigCommerceCoverage::DISAGREEMENTS];
        $before = array_map(fn ($f) => hash_file('sha256', "$root/$f"), $files);
        (new BigCommerceCoverage)->generate($root);
        (new AdobeCommerceCoverage)->generate($root);
        (new GoogleMerchantCoverage)->generate($root);
        (new AmazonCoverage)->generate($root);
        (new BigCommerceCoverage)->validate($root);
        (new AdobeCommerceCoverage)->validate($root);
        (new GoogleMerchantCoverage)->validate($root);
        (new AmazonCoverage)->validate($root);
        $this->assertSame($before, array_map(fn ($f) => hash_file('sha256', "$root/$f"), $files));
    }

    #[Test]
    public function unconditional_ptd_capability_regression_fails(): void
    {
        $root = $this->temporaryCorpus();
        $rows = $this->readCsv("$root/".AmazonCoverage::COVERAGE);
        foreach ($rows as &$row) {
            if ($row['external_key'] === 'item_name') {
                $row['read_semantics'] = 'unconditional_live_read';
            }
        }
        $this->writeCsv("$root/".AmazonCoverage::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $rows);
        $this->expectException(RuntimeException::class);
        (new AmazonCoverage)->validate($root);
    }

    #[Test]
    public function invented_applicability_key_regression_fails(): void
    {
        $root = $this->temporaryCorpus();
        $rows = $this->readCsv("$root/".AmazonCoverage::COVERAGE);
        $rows[0]['applicability_key'] = 'amazon:invented-applicability';
        $this->writeCsv("$root/".AmazonCoverage::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $rows);
        $this->expectException(RuntimeException::class);
        (new AmazonCoverage)->validate($root);
    }

    private function luggageRows(): array
    {
        return array_column(array_filter($this->rows(), fn ($r) => $r['source_file'] === AmazonCoverage::LUGGAGE), null, 'external_key');
    }

    private function rows(): array
    {
        return $this->readCsv(dirname(__DIR__, 3).'/'.AmazonCoverage::COVERAGE);
    }

    private function readCsv(string $path): array
    {
        $h = fopen($path, 'rb');
        $head = fgetcsv($h, null, ',', '"', '');
        $rows = [];
        while (($v = fgetcsv($h, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($head, $v);
        } fclose($h);

        return $rows;
    }

    private function writeCsv(string $path, array $header, array $rows): void
    {
        $h = fopen($path, 'wb');
        fputcsv($h, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($h, array_values($row), ',', '"', '');
        } fclose($h);
    }

    #[Test]
    public function missing_pre_amazon_provider_identity_fails_even_when_later_provider_rows_exist(): void
    {
        $root = $this->temporaryCorpus();
        $manifest = $this->readCsv("$root/".AmazonCoverage::MANIFEST);
        $manifest = array_values(array_filter($manifest, fn ($row) => $row['source_file'] !== 'docs/data/adobe_commerce_v1_alias_groups.csv'));
        $this->writeCsv("$root/".AmazonCoverage::MANIFEST, BigCommerceCoverage::MANIFEST_HEADER, $manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('accepted pre-Amazon manifest identity missing');
        (new AmazonCoverage)->validate($root);
    }

    private function temporaryCorpus(): string
    {
        $source = dirname(__DIR__, 3);
        $root = sys_get_temp_dir().'/amazon-coverage-'.bin2hex(random_bytes(6));
        foreach ([AmazonCoverage::META, AmazonCoverage::LUGGAGE, AmazonCoverage::MANIFEST, AmazonCoverage::COVERAGE, AmazonCoverage::CONCEPTS, AmazonCoverage::DISAGREEMENTS] as $file) {
            if (! is_dir($root.'/'.dirname($file))) {
                mkdir($root.'/'.dirname($file), 0777, true);
            } copy("$source/$file", "$root/$file");
        }

        return $root;
    }
}
