<?php

namespace Tests\Unit\CanonicalCoverage;

use App\Support\CanonicalCoverage\BigCommerceCoverage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BigCommerceCoverageTest extends TestCase
{
    #[Test]
    public function committed_provider_slice_has_complete_reproducible_coverage(): void
    {
        $metrics = (new BigCommerceCoverage)->validate(dirname(__DIR__, 3));

        $this->assertSame(136, $metrics['manifest_rows']);
        $this->assertSame(136, $metrics['coverage_rows']);
        $this->assertSame(1.0, $metrics['coverage_ratio']);
        $this->assertSame(1.0, $metrics['classification_ratio']);
        $this->assertSame(1.0, $metrics['concept_link_ratio']);
        $this->assertSame(1.0, $metrics['terminal_rationale_ratio']);
        $this->assertSame(0, $metrics['silent_drop_count']);
    }

    #[Test]
    public function generation_is_byte_for_byte_deterministic(): void
    {
        $service = new BigCommerceCoverage;
        $root = dirname(__DIR__, 3);
        $service->validate($root);
        $artifacts = [BigCommerceCoverage::MANIFEST, BigCommerceCoverage::COVERAGE, BigCommerceCoverage::CONCEPTS, BigCommerceCoverage::DISAGREEMENTS];
        $before = array_map(fn ($file) => hash_file('sha256', "$root/$file"), $artifacts);
        $service->generate($root);
        $after = array_map(fn ($file) => hash_file('sha256', "$root/$file"), $artifacts);

        $this->assertSame($before, $after);
    }

    #[Test]
    public function regeneration_preserves_a_second_provider_manifest_row(): void
    {
        $root = $this->temporaryCorpus();
        $manifest = $this->readCsv("$root/".BigCommerceCoverage::MANIFEST);
        $before = [];
        foreach ($manifest as $entry) {
            $before[$entry['platform'].'|'.$entry['source_file']] = $entry;
        }
        $manifest[] = ['other-v1', BigCommerceCoverage::BASE_COMMIT, 'other', 'docs/data/other.csv', str_repeat('a', 64), str_repeat('b', 64), '1', 'test', '2026-09-07'];
        $this->writeCsv("$root/".BigCommerceCoverage::MANIFEST, BigCommerceCoverage::MANIFEST_HEADER, $manifest);

        (new BigCommerceCoverage)->generate($root);
        $after = $this->readCsv("$root/".BigCommerceCoverage::MANIFEST);

        $this->assertCount(count($manifest), $after);
        $afterByIdentity = [];
        foreach ($after as $entry) {
            $afterByIdentity[$entry['platform'].'|'.$entry['source_file']] = $entry;
        }
        $this->assertSame('other-v1', $afterByIdentity['other|docs/data/other.csv']['snapshot_id']);
        foreach ($before as $identity => $entry) {
            $this->assertSame($entry, $afterByIdentity[$identity]);
        }
    }

    #[Test]
    public function duplicate_manifest_identity_is_rejected(): void
    {
        $root = $this->temporaryCorpus();
        $rows = $this->readCsv("$root/".BigCommerceCoverage::MANIFEST);
        $rows[] = $rows[0];
        $this->writeCsv("$root/".BigCommerceCoverage::MANIFEST, BigCommerceCoverage::MANIFEST_HEADER, $rows);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate manifest identity');
        (new BigCommerceCoverage)->validate($root);
    }

    #[Test]
    public function shared_product_variant_wire_differences_use_explicit_neutral_types(): void
    {
        $root = dirname(__DIR__, 3);
        $concepts = array_column($this->readCsv("$root/".BigCommerceCoverage::CONCEPTS), null, 'concept_key');

        $this->assertSame('decimal', $concepts['bigcommerce:price']['value_type']);
        $this->assertSame('decimal', $concepts['bigcommerce:weight']['value_type']);
        $this->assertSame('string', $concepts['bigcommerce:sku']['value_type']);
        $this->assertSame('ProductAndProductVariant', $concepts['bigcommerce:price']['entity_level']);
    }

    #[Test]
    public function unexplained_shared_concept_merge_is_rejected(): void
    {
        $root = $this->temporaryCorpus();
        $coverage = $this->readCsv("$root/".BigCommerceCoverage::COVERAGE);
        foreach ($coverage as &$row) {
            if ($row['concept_key'] === 'bigcommerce:product:name') {
                $row['concept_key'] = 'bigcommerce:product:description';
            }
        }
        $this->writeCsv("$root/".BigCommerceCoverage::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $coverage);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexplained shared-concept compatibility');
        (new BigCommerceCoverage)->validate($root);
    }

    #[Test]
    public function required_operation_context_and_owner_splits_are_preserved(): void
    {
        $coverage = $this->coverageByObjectAndKey(dirname(__DIR__, 3));

        $this->assertStringContainsString('required_in=', $coverage['product:name']['source_context_key']);
        $this->assertStringContainsString('schema_evidence=', $coverage['product:name']['source_context_key']);
        $this->assertSame(['Category', 'category_relation'], $this->ownerPair($coverage['product:categories']));
        $this->assertSame(['ProductAssociation', 'product_relationship'], $this->ownerPair($coverage['product:related_products']));
        $this->assertSame(['DynamicField', 'external_custom_field_container'], $this->ownerPair($coverage['product:custom_fields']));
        $this->assertSame(['VariantComposition', 'variant_composition'], $this->ownerPair($coverage['product:variants']));
        $this->assertSame(['VariantComposition', 'variant_composition'], $this->ownerPair($coverage['product_option:option_values']));
        $this->assertSame(['OrderCustomization', 'order_time_customization'], $this->ownerPair($coverage['product_modifier:option_values']));
    }

    #[Test]
    public function disagreement_counts_and_deferred_row_statuses_are_enforced(): void
    {
        $root = $this->temporaryCorpus();
        $coverage = $this->coverageByObjectAndKey($root);

        $this->assertSame('DEFERRED_REVIEW', $coverage['product:tax_class_id']['review_status']);
        $this->assertSame('UnresolvedTaxOwner', $coverage['product:tax_class_id']['owner_candidate']);
        $this->assertStringContainsString('queue:bigcommerce_tax_owner', $coverage['product:tax_class_id']['decision_reference']);

        $questions = $this->readCsv("$root/".BigCommerceCoverage::DISAGREEMENTS);
        $questions[0]['affected_coverage_count'] = '999';
        $this->writeCsv("$root/".BigCommerceCoverage::DISAGREEMENTS, BigCommerceCoverage::DISAGREEMENT_HEADER, $questions);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stale disagreement count');
        (new BigCommerceCoverage)->validate($root);
    }

    #[Test]
    public function open_disagreement_cannot_leave_affected_row_verified(): void
    {
        $root = $this->temporaryCorpus();
        $coverage = $this->readCsv("$root/".BigCommerceCoverage::COVERAGE);
        foreach ($coverage as &$row) {
            if ($row['concept_key'] === 'bigcommerce:product:map_price') {
                $row['review_status'] = 'PROVIDER_VERIFIED';
            }
        }
        $this->writeCsv("$root/".BigCommerceCoverage::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $coverage);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('open disagreement silently verified');
        (new BigCommerceCoverage)->validate($root);
    }

    #[Test]
    public function stale_disagreement_concept_reference_is_rejected(): void
    {
        $root = $this->temporaryCorpus();
        $questions = $this->readCsv("$root/".BigCommerceCoverage::DISAGREEMENTS);
        $questions[0]['evidence_concept_keys'] = 'bigcommerce:missing:concept';
        $questions[0]['affected_coverage_count'] = '0';
        $this->writeCsv("$root/".BigCommerceCoverage::DISAGREEMENTS, BigCommerceCoverage::DISAGREEMENT_HEADER, $questions);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid disagreement concept');
        (new BigCommerceCoverage)->validate($root);
    }

    #[Test]
    public function custom_url_has_no_false_open_graph_title_conflict(): void
    {
        $concepts = array_column($this->readCsv(dirname(__DIR__, 3).'/'.BigCommerceCoverage::CONCEPTS), null, 'concept_key');

        $this->assertStringNotContainsString('open_graph_title', $concepts['bigcommerce:product:custom_url']['conflicts_with_concept_keys']);
    }

    private function temporaryCorpus(): string
    {
        $source = dirname(__DIR__, 3);
        $root = sys_get_temp_dir().'/bigcommerce-coverage-'.bin2hex(random_bytes(8));
        foreach ([BigCommerceCoverage::SOURCE, BigCommerceCoverage::MANIFEST, BigCommerceCoverage::COVERAGE, BigCommerceCoverage::CONCEPTS, BigCommerceCoverage::DISAGREEMENTS] as $file) {
            if (! is_dir($root.'/'.dirname($file))) {
                mkdir($root.'/'.dirname($file), 0777, true);
            }
            copy("$source/$file", "$root/$file");
        }

        return $root;
    }

    /** @return array<string, array<string, string>> */
    private function coverageByObjectAndKey(string $root): array
    {
        $result = [];
        foreach ($this->readCsv("$root/".BigCommerceCoverage::COVERAGE) as $row) {
            $result[$row['source_object_family'].':'.$row['external_key']] = $row;
        }

        return $result;
    }

    /** @return array{string, string} */
    private function ownerPair(array $row): array
    {
        return [$row['owner_candidate'], $row['representation_candidate']];
    }

    /** @return list<array<string, string>> */
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
