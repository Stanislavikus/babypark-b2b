<?php

namespace Tests\Unit\CanonicalCoverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GoogleMerchantSemanticReviewCorrectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
    }

    #[Test]
    public function products_v1_offer_identity_uses_literal_snapshot_key(): void
    {
        $rows = $this->rows('docs/data/canonical_product_field_mappings.csv');
        $mapping = $this->mapping($rows, 'sku', 'products_v1-2026-09-07');

        self::assertSame('offerId', $mapping['external_field']);
        self::assertSame('verified', $mapping['verification_status']);
        self::assertSame('mapping:google_merchant:sku:offerId:a062:products_v1-2026-09-07', $mapping['evidence_subject_key']);
    }

    #[Test]
    public function identifier_exists_keeps_positive_external_polarity(): void
    {
        $rows = $this->rows('docs/data/canonical_product_field_mappings.csv');
        $legacy = $this->mapping($rows, 'identifier_exists', 'unversioned');
        $current = $this->mapping($rows, 'identifier_exists', 'products_v1-2026-09-07');

        self::assertSame('false_only_when_identifiers_genuinely_absent', $legacy['transformation']);
        self::assertSame('false_only_when_identifiers_genuinely_absent', $current['transformation']);

        $fields = $this->index($this->rows('docs/data/canonical_product_fields.csv'), 'internal_code');
        self::assertStringContainsString('indicating whether', $fields['identifier_exists']['description']);
        self::assertStringContainsString('set false only', $fields['identifier_exists']['description']);

        $registry = file_get_contents($this->root.'/docs/CANONICAL_PRODUCT_FIELD_REGISTRY.md');
        self::assertIsString($registry);
        self::assertStringNotContainsString('true_only_when_identifiers_genuinely_absent', $registry);
    }

    #[Test]
    public function google_weight_relations_remain_fail_closed_on_packaging_semantics(): void
    {
        $rows = $this->rows('docs/data/canonical_product_field_mappings.csv');
        foreach ([
            'net_weight' => 'productWeight',
            'gross_weight' => 'shippingWeight',
        ] as $code => $external) {
            $mapping = $this->mapping($rows, $code, 'products_v1-2026-09-07');
            self::assertSame($external, $mapping['external_field']);
            self::assertSame('partially_verified', $mapping['verification_status']);
        }

        $synthesis = $this->index($this->rows('docs/data/cross_platform_product_field_synthesis.csv'), 'concept_key');
        self::assertStringContainsString('packaging', $synthesis['net_weight']['google_evidence']);
        self::assertStringContainsString('mapping partial', $synthesis['net_weight']['google_evidence']);
        self::assertStringContainsString('NOT established', $synthesis['gross_weight']['google_evidence']);
        self::assertStringContainsString('mapping partial', $synthesis['gross_weight']['google_evidence']);
    }

    #[Test]
    public function google_gender_other_stays_explicitly_unmapped(): void
    {
        $options = $this->index($this->rows('docs/data/canonical_product_field_options.csv'), 'option_id');
        self::assertSame('other', $options['o017']['option_code']);

        $mappings = $this->rows('docs/data/canonical_product_field_option_mappings.csv');
        self::assertSame([], array_values(array_filter($mappings, fn (array $row): bool => $row['channel'] === 'google_merchant' && $row['option_id'] === 'o017')));
        $sources = $this->rows('docs/data/canonical_product_field_sources.csv');
        $source = collect($sources)->first(fn (array $row): bool => $row['evidence_subject_key'] === 'option:o017');
        self::assertIsArray($source);
        self::assertStringContainsString('No Google equivalent', $source['evidence_note']);
    }

    #[Test]
    public function primary_google_link_mapping_stays_verified_while_sibling_roles_remain_contextual(): void
    {
        $rows = $this->rows('docs/data/canonical_product_field_mappings.csv');
        $mapping = $this->mapping($rows, 'url', 'products_v1-2026-09-07');
        self::assertSame('link', $mapping['external_field']);
        self::assertSame('transformed', $mapping['mapping_type']);
        self::assertSame('verified', $mapping['verification_status']);

        $synthesis = $this->index($this->rows('docs/data/cross_platform_product_field_synthesis.csv'), 'concept_key');
        self::assertStringContainsString('primary merchant landing-page representation', $synthesis['url']['google_evidence']);
        self::assertStringContainsString('canonicalLink/mobileLink', $synthesis['url']['google_evidence']);
    }

    /** @return list<array<string, string>> */
    private function rows(string $relativePath): array
    {
        $handle = fopen($this->root.'/'.$relativePath, 'rb');
        self::assertNotFalse($handle);
        $header = fgetcsv($handle, null, ',', '"', '');
        self::assertIsArray($header);
        $rows = [];
        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            self::assertCount(count($header), $values);
            $row = array_combine($header, $values);
            self::assertIsArray($row);
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /** @param list<array<string, string>> $rows */
    private function index(array $rows, string $column): array
    {
        return array_column($rows, null, $column);
    }

    /** @param list<array<string, string>> $rows */
    private function mapping(array $rows, string $internalCode, string $version): array
    {
        $matches = array_values(array_filter($rows, fn (array $row): bool => $row['channel'] === 'google_merchant'
            && $row['internal_code'] === $internalCode && $row['channel_schema_version'] === $version));
        self::assertCount(1, $matches, $internalCode.' '.$version);

        return $matches[0];
    }
}
