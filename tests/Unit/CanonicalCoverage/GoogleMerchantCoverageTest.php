<?php

namespace Tests\Unit\CanonicalCoverage;

use App\Support\CanonicalCoverage\AdobeCommerceCoverage;
use App\Support\CanonicalCoverage\BigCommerceCoverage;
use App\Support\CanonicalCoverage\GoogleMerchantCoverage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GoogleMerchantCoverageTest extends TestCase
{
    #[Test]
    public function committed_google_slice_is_complete(): void
    {
        $m = (new GoogleMerchantCoverage)->validate(dirname(__DIR__, 3));
        $this->assertSame(145, $m['attribute_rows']);
        $this->assertSame(11, $m['product_input_rows']);
        $this->assertSame(156, $m['coverage_rows']);
        $this->assertSame(1.0, $m['coverage_ratio']);
        $this->assertSame(0, $m['silent_drop_count']);
    }

    #[Test]
    public function product_input_fates_are_explicit(): void
    {
        $r = $this->rows('ProductInput');
        $this->assertSame('EXTERNAL_IDENTITY', $r['offerId']['disposition']);
        foreach (['name', 'product', 'base64EncodedName', 'base64EncodedProduct', 'versionNumber'] as $key) {
            $this->assertSame('TRANSPORT_MECHANIC', $r[$key]['disposition']);
        }
        foreach (['contentLanguage', 'feedLabel', 'legacyLocal'] as $key) {
            $this->assertSame('CHANNEL_SEMANTIC', $r[$key]['disposition']);
        }
        foreach (['productAttributes', 'customAttributes'] as $key) {
            $this->assertSame('submission_attribute_container', $r[$key]['representation_candidate']);
        }
    }

    #[Test]
    public function manifest_contains_both_exact_google_source_hashes(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = array_column($this->readCsv("$root/".GoogleMerchantCoverage::MANIFEST), null, 'source_file');
        foreach ([GoogleMerchantCoverage::ATTRIBUTES, GoogleMerchantCoverage::PRODUCT_INPUT] as $file) {
            $this->assertSame(hash_file('sha256', "$root/$file"), $manifest[$file]['file_sha256']);
        }
    }

    #[Test]
    public function processed_attributes_preserve_conditional_binding_and_domains(): void
    {
        $r = $this->rows('ProductAttributes');
        foreach ($r as $row) {
            $this->assertSame('processed_publication_output', $row['read_semantics']);
            $this->assertSame('conditional_write_by_data_spec', $row['write_semantics']);
        }
        foreach (['brand', 'gtins', 'mpn'] as $key) {
            $this->assertSame('REUSABLE_SEMANTIC', $r[$key]['disposition']);
        }
        foreach (['customLabel0', 'customLabel1', 'customLabel2', 'customLabel3', 'customLabel4', 'includedDestinations', 'excludedDestinations'] as $key) {
            $this->assertSame('CHANNEL_SEMANTIC', $r[$key]['disposition']);
        }
        $this->assertSame('google_taxonomy_context', $r['googleProductCategory']['representation_candidate']);
        $this->assertSame('Pricing', $r['price']['owner_candidate']);
        $this->assertNotSame($r['price']['concept_key'], $r['salePrice']['concept_key']);
        $this->assertSame('Media', $r['imageLink']['owner_candidate']);
        $this->assertSame('ShippingReturns', $r['shipping']['owner_candidate']);
    }

    #[Test]
    public function verticals_preserve_scope_independently_of_semantic_owner(): void
    {
        $r = $this->rows('ProductAttributes');
        foreach (['dateFirstRegistered', 'model'] as $key) {
            $this->assertSame('CATEGORY_ATTRIBUTE', $r[$key]['disposition']);
            $this->assertStringContainsString('vertical=vehicle', $r[$key]['source_context_key']);
        }
        foreach (['vehicleAllInPrice', 'vehicleExpenses', 'vehicleMsrp', 'vehiclePriceType'] as $key) {
            $this->assertSame('Pricing', $r[$key]['owner_candidate']);
            $this->assertStringContainsString('vertical=vehicle', $r[$key]['source_context_key']);
        }
        $this->assertSame('Pricing', $r['productFee']['owner_candidate']);
        $this->assertStringContainsString('vertical=property', $r['productFee']['source_context_key']);
        foreach (['co2Emissions', 'emissionsStandard', 'energyConsumption', 'vehicleMandatoryInspectionIncluded', 'warranty'] as $key) {
            $this->assertSame('DEFER_DECISION', $r[$key]['disposition']);
            $this->assertSame('Compliance', $r[$key]['owner_candidate']);
            $this->assertStringContainsString('vertical=vehicle', $r[$key]['source_context_key']);
        }
        foreach ($r as $row) {
            $this->assertSame('not_applicable', $row['applicability_key']);
        }
        $scoped = array_filter($r, fn ($row) => ! str_contains($row['source_context_key'], 'vertical=not_applicable'));
        $this->assertCount(37, $scoped);
    }

    #[Test]
    public function relationship_and_variant_families_have_explicit_distinct_fates(): void
    {
        $r = $this->rows('ProductAttributes');
        $this->assertSame('ProductAssociation', $r['relatedProducts']['owner_candidate']);
        foreach (['itemGroupId', 'itemGroupTitle', 'variantOptions'] as $key) {
            $this->assertSame('VariantComposition', $r[$key]['owner_candidate']);
        }
        foreach (['isBundle', 'multipack'] as $key) {
            $this->assertNotSame('ProductAssociation', $r[$key]['owner_candidate']);
        }
    }

    #[Test]
    public function deferred_candidates_and_specialized_contexts_are_explicit(): void
    {
        $r = $this->rows('ProductAttributes');
        foreach (['unitPricingMeasure', 'unitPricingBaseMeasure'] as $key) {
            $this->assertSame('DEFER_DECISION', $r[$key]['disposition']);
            $this->assertSame('PricingOrCompliance', $r[$key]['owner_candidate']);
            $this->assertNotSame('Connector', $r[$key]['owner_candidate']);
            $this->assertSame('DEFERRED_REVIEW', $r[$key]['review_status']);
            $this->assertStringContainsString('queue:google_unit_pricing', $r[$key]['decision_reference']);
        }
        $this->assertSame('DEFER_DECISION', $r['shortTitle']['disposition']);
        $this->assertSame('FieldDefinitionOrContent', $r['shortTitle']['owner_candidate']);
        $this->assertSame('DEFERRED_REVIEW', $r['shortTitle']['review_status']);
        $this->assertStringContainsString('queue:google_short_title_ownership', $r['shortTitle']['decision_reference']);
        $this->assertSame('google_publication_quantity_control', $r['sellOnGoogleQuantity']['representation_candidate']);
        $this->assertSame('sustainability_incentive_program_candidate', $r['sustainabilityIncentives']['representation_candidate']);
    }

    #[Test]
    public function all_provider_generators_round_trip_byte_identically(): void
    {
        $root = dirname(__DIR__, 3);
        $files = [GoogleMerchantCoverage::MANIFEST, GoogleMerchantCoverage::COVERAGE, GoogleMerchantCoverage::CONCEPTS, GoogleMerchantCoverage::DISAGREEMENTS, AdobeCommerceCoverage::COVERAGE, AdobeCommerceCoverage::CONCEPTS, AdobeCommerceCoverage::DISAGREEMENTS, BigCommerceCoverage::COVERAGE, BigCommerceCoverage::CONCEPTS, BigCommerceCoverage::DISAGREEMENTS];
        $before = array_map(fn ($f) => hash_file('sha256', "$root/$f"), $files);
        (new GoogleMerchantCoverage)->generate($root);
        (new AdobeCommerceCoverage)->generate($root);
        (new BigCommerceCoverage)->generate($root);
        (new GoogleMerchantCoverage)->validate($root);
        (new AdobeCommerceCoverage)->validate($root);
        (new BigCommerceCoverage)->validate($root);
        $this->assertSame($before, array_map(fn ($f) => hash_file('sha256', "$root/$f"), $files));
    }

    #[Test]
    public function conditional_write_and_open_disagreement_regressions_fail(): void
    {
        $root = $this->temporaryCorpus();
        $rows = $this->readCsv("$root/".GoogleMerchantCoverage::COVERAGE);
        foreach ($rows as &$row) {
            if ($row['external_key'] === 'availability') {
                $row['write_semantics'] = 'writable';
                $row['review_status'] = 'PROVIDER_VERIFIED';
            }
        }
        $this->writeCsv("$root/".GoogleMerchantCoverage::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $rows);
        $this->expectException(RuntimeException::class);
        (new GoogleMerchantCoverage)->validate($root);
    }

    private function rows(string $object): array
    {
        $h = fopen(dirname(__DIR__, 3).'/'.GoogleMerchantCoverage::COVERAGE, 'rb');
        $head = fgetcsv($h, null, ',', '"', '');
        $rows = [];
        while (($v = fgetcsv($h, null, ',', '"', '')) !== false) {
            $r = array_combine($head, $v);
            if ($r['source_object_family'] === $object) {
                $rows[$r['external_key']] = $r;
            }
        }
        fclose($h);

        return $rows;
    }

    private function temporaryCorpus(): string
    {
        $source = dirname(__DIR__, 3);
        $root = sys_get_temp_dir().'/google-coverage-'.bin2hex(random_bytes(6));
        foreach ([GoogleMerchantCoverage::ATTRIBUTES, GoogleMerchantCoverage::PRODUCT_INPUT, GoogleMerchantCoverage::MANIFEST, GoogleMerchantCoverage::COVERAGE, GoogleMerchantCoverage::CONCEPTS, GoogleMerchantCoverage::DISAGREEMENTS] as $file) {
            if (! is_dir($root.'/'.dirname($file))) {
                mkdir($root.'/'.dirname($file), 0777, true);
            }
            copy("$source/$file", "$root/$file");
        }

        return $root;
    }

    private function readCsv(string $path): array
    {
        $h = fopen($path, 'rb');
        $head = fgetcsv($h, null, ',', '"', '');
        $rows = [];
        while (($values = fgetcsv($h, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($head, $values);
        }
        fclose($h);

        return $rows;
    }

    private function writeCsv(string $path, array $header, array $rows): void
    {
        $h = fopen($path, 'wb');
        fputcsv($h, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($h, array_values($row), ',', '"', '');
        }
        fclose($h);
    }
}
