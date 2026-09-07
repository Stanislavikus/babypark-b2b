<?php

namespace Tests\Unit\CanonicalCoverage;

use App\Support\CanonicalCoverage\AdobeCommerceCoverage;
use App\Support\CanonicalCoverage\BigCommerceCoverage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AdobeCommerceCoverageTest extends TestCase
{
    #[Test]
    public function committed_adobe_slice_has_complete_validated_coverage(): void
    {
        $metrics = (new AdobeCommerceCoverage)->validate(dirname(__DIR__, 3));

        $this->assertSame(183, $metrics['master_rows']);
        $this->assertSame(189, $metrics['structured_rows']);
        $this->assertSame(39, $metrics['alias_rows']);
        $this->assertSame(411, $metrics['coverage_rows']);
        $this->assertEquals(1.0, $metrics['coverage_ratio']);
        $this->assertEquals(1.0, $metrics['classification_ratio']);
        $this->assertEquals(1.0, $metrics['concept_link_ratio']);
        $this->assertSame(0, $metrics['silent_drop_count']);
        $this->assertSame(0, $metrics['orphan_structured_parent_count']);
        $this->assertSame(0, $metrics['invalid_alias_reference_count']);
    }

    #[Test]
    public function manifest_contains_all_three_adobe_hashes_and_bigcommerce(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = array_column($this->readCsv("$root/".AdobeCommerceCoverage::MANIFEST), null, 'source_file');

        $this->assertCount(4, $manifest);
        foreach ([AdobeCommerceCoverage::MASTER, AdobeCommerceCoverage::STRUCTURED, AdobeCommerceCoverage::ALIASES] as $file) {
            $this->assertSame(hash_file('sha256', "$root/$file"), $manifest[$file]['file_sha256']);
        }
        $this->assertArrayHasKey(BigCommerceCoverage::SOURCE, $manifest);
    }

    #[Test]
    public function generation_and_cross_provider_round_trip_are_byte_deterministic(): void
    {
        $root = dirname(__DIR__, 3);
        $adobe = new AdobeCommerceCoverage;
        $bigCommerce = new BigCommerceCoverage;
        $files = [AdobeCommerceCoverage::MANIFEST, AdobeCommerceCoverage::COVERAGE, AdobeCommerceCoverage::CONCEPTS, AdobeCommerceCoverage::DISAGREEMENTS, BigCommerceCoverage::COVERAGE, BigCommerceCoverage::CONCEPTS, BigCommerceCoverage::DISAGREEMENTS];
        $before = $this->hashes($root, $files);

        $adobe->generate($root);
        $bigCommerce->generate($root);
        $adobe->validate($root);
        $bigCommerce->validate($root);

        $this->assertSame($before, $this->hashes($root, $files));
    }

    #[Test]
    public function structured_members_have_valid_parents_and_nested_identifiers_stay_members(): void
    {
        $rows = $this->coverage(dirname(__DIR__, 3));
        $ids = array_column($rows, null, 'coverage_id');
        $structured = array_filter($rows, fn ($row) => $row['source_file'] === AdobeCommerceCoverage::STRUCTURED);

        $this->assertCount(189, $structured);
        foreach ($structured as $row) {
            $this->assertSame('STRUCTURE_MEMBER', $row['disposition']);
            $this->assertArrayHasKey($row['parent_coverage_id'], $ids);
        }
        foreach (array_filter($structured, fn ($row) => in_array($row['external_key'], ['id', 'uid', 'sku', 'code'], true)) as $anchor) {
            $this->assertNotSame('EXTERNAL_IDENTITY', $anchor['disposition']);
        }
    }

    #[Test]
    public function aliases_preserve_targets_and_non_raw_equal_transformations(): void
    {
        $rows = $this->coverage(dirname(__DIR__, 3));
        $ids = array_column($rows, null, 'coverage_id');
        $aliases = array_filter($rows, fn ($row) => $row['source_file'] === AdobeCommerceCoverage::ALIASES);

        $this->assertCount(39, $aliases);
        foreach ($aliases as $alias) {
            $this->assertSame('ALIAS_REPRESENTATION', $alias['disposition']);
            $this->assertArrayHasKey($alias['alias_of_coverage_id'], $ids);
            $this->assertStringContainsString('identity_rule=', $alias['source_context_key']);
        }
        $taxName = $this->find($aliases, 'external_key', 'tax_class_name');
        $this->assertSame('alias_with_transformation', $taxName['representation_candidate']);
    }

    #[Test]
    public function critical_adobe_boundaries_remain_provider_local(): void
    {
        $master = $this->masterRows(dirname(__DIR__, 3));

        $this->assertSame(['Connector', 'provider_scope_context'], $this->owner($master['visibility']));
        $this->assertSame('AttributeSchema', $master['attribute_sets']['owner_candidate']);
        $this->assertSame('Connector', $master['website_assignment']['owner_candidate']);
        $this->assertSame('Category', $master['categories']['owner_candidate']);
        $this->assertSame('ProductAssociation', $master['product_links']['owner_candidate']);
        $this->assertSame('VariantComposition', $master['configurable_product_options']['owner_candidate']);
        $this->assertSame('OrderCustomization', $master['custom_option_definition']['owner_candidate']);
        $this->assertSame('BundleComposition', $master['bundle_values']['owner_candidate']);
        $this->assertSame('GroupedComposition', $master['grouped_product_item']['owner_candidate']);
        $this->assertSame('DownloadableComposition', $master['downloadable_link']['owner_candidate']);
        $this->assertSame('SharedCatalog', $master['shared_catalog_product_membership']['owner_candidate']);
        $this->assertSame('GiftCard', $master['giftcard_amounts']['owner_candidate']);
    }

    #[Test]
    public function pricing_inventory_media_and_catalog_projections_stay_separate(): void
    {
        $master = $this->masterRows(dirname(__DIR__, 3));

        $this->assertNotSame($master['price']['concept_key'], $master['special_price']['concept_key']);
        $this->assertNotSame($master['map_price']['concept_key'], $master['msrp_price']['concept_key']);
        $this->assertSame('Inventory', $master['inventory_source']['owner_candidate']);
        $this->assertSame('Availability', $master['salable_quantity']['owner_candidate']);
        $this->assertSame('Media', $master['media_gallery_entries']['owner_candidate']);
        foreach ($master as $key => $row) {
            if (str_starts_with($key, 'catalog_service_')) {
                $this->assertSame('DERIVED_PROJECTION', $row['disposition']);
                $this->assertSame('read_only', $row['write_semantics']);
            }
        }
    }

    #[Test]
    public function rma_gift_wrap_and_other_open_questions_are_deferred_in_ledger(): void
    {
        $master = $this->masterRows(dirname(__DIR__, 3));
        foreach (['rma_eligibility', 'is_returnable', 'gift_wrapping_capability', 'gift_wrapping_available', 'gift_wrapping_price'] as $key) {
            $this->assertSame('DEFERRED_REVIEW', $master[$key]['review_status']);
            $this->assertStringContainsString('queue:adobe_', $master[$key]['decision_reference']);
        }
        $questions = $this->readCsv(dirname(__DIR__, 3).'/'.AdobeCommerceCoverage::DISAGREEMENTS);
        $this->assertCount(14, $questions);
    }

    #[Test]
    public function unexplained_adobe_concept_merge_fails_validation(): void
    {
        $root = $this->temporaryCorpus();
        $rows = $this->readCsv("$root/".AdobeCommerceCoverage::COVERAGE);
        $rows[0]['concept_key'] = $rows[1]['concept_key'];
        $this->writeCsv("$root/".AdobeCommerceCoverage::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $rows);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexplained shared Adobe concept');
        (new AdobeCommerceCoverage)->validate($root);
    }

    #[Test]
    public function bigcommerce_validator_remains_green_with_adobe_manifest_rows(): void
    {
        $metrics = (new BigCommerceCoverage)->validate(dirname(__DIR__, 3));
        $this->assertSame(136, $metrics['coverage_rows']);
        $this->assertSame('PASS', $metrics['manifest_provider_rows_preserved']);
    }

    private function temporaryCorpus(): string
    {
        $source = dirname(__DIR__, 3);
        $root = sys_get_temp_dir().'/adobe-coverage-'.bin2hex(random_bytes(8));
        $files = [AdobeCommerceCoverage::MASTER, AdobeCommerceCoverage::STRUCTURED, AdobeCommerceCoverage::ALIASES, AdobeCommerceCoverage::CLUSTERS, AdobeCommerceCoverage::SOURCES, AdobeCommerceCoverage::MANIFEST, AdobeCommerceCoverage::COVERAGE, AdobeCommerceCoverage::CONCEPTS, AdobeCommerceCoverage::DISAGREEMENTS];
        foreach ($files as $file) {
            if (! is_dir($root.'/'.dirname($file))) {
                mkdir($root.'/'.dirname($file), 0777, true);
            }
            copy("$source/$file", "$root/$file");
        }

        return $root;
    }

    private function coverage(string $root): array
    {
        return $this->readCsv("$root/".AdobeCommerceCoverage::COVERAGE);
    }

    private function masterRows(string $root): array
    {
        $rows = array_filter($this->coverage($root), fn ($row) => $row['source_file'] === AdobeCommerceCoverage::MASTER);

        return array_column($rows, null, 'external_key');
    }

    private function find(array $rows, string $column, string $value): array
    {
        foreach ($rows as $row) {
            if ($row[$column] === $value) {
                return $row;
            }
        }
        throw new RuntimeException("Missing $column=$value");
    }

    private function owner(array $row): array
    {
        return [$row['owner_candidate'], $row['representation_candidate']];
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
            $rows[] = array_combine($header, $values);
        }
        fclose($handle);

        return $rows;
    }

    private function writeCsv(string $path, array $header, array $rows): void
    {
        $handle = fopen($path, 'wb');
        fputcsv($handle, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ',', '"', '');
        }
        fclose($handle);
    }
}
