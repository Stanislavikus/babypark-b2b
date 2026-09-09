<?php

namespace Tests\Unit\CanonicalCoverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AmazonSemanticReviewCorrectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
    }

    #[Test]
    public function condition_and_offer_pricing_boundaries_are_explicit(): void
    {
        $coverage = $this->index($this->rows('docs/data/canonical-coverage/amazon.csv'), 'external_key');

        self::assertSame('ProductVariantData', $coverage['condition_type']['owner_candidate']);
        self::assertSame('product_condition_enum', $coverage['condition_type']['representation_candidate']);
        self::assertSame('amazon_purchasable_offer_structured_envelope', $coverage['purchasable_offer']['representation_candidate']);
        self::assertSame('DEFERRED_REVIEW', $coverage['purchasable_offer']['review_status']);
        self::assertSame('PROVIDER_VERIFIED', $coverage['list_price']['review_status']);
    }

    #[Test]
    public function dec009_weight_mappings_fail_closed_until_packaging_boundaries_are_proven(): void
    {
        $mappings = $this->rows('docs/data/canonical_product_field_mappings.csv');
        foreach (['net_weight' => 'item_weight', 'gross_weight' => 'item_package_weight'] as $code => $external) {
            $mapping = $this->amazonMapping($mappings, $code);
            self::assertSame($external, $mapping['external_field']);
            self::assertSame('partially_verified', $mapping['verification_status']);
        }

        $coverage = $this->index($this->rows('docs/data/canonical-coverage/amazon.csv'), 'external_key');
        self::assertSame('DEFERRED_REVIEW', $coverage['item_weight']['review_status']);
        self::assertSame('DEFERRED_REVIEW', $coverage['item_package_weight']['review_status']);

        $synthesis = $this->index($this->rows('docs/data/cross_platform_product_field_synthesis.csv'), 'concept_key');
        self::assertStringContainsString('mapping partial', $synthesis['net_weight']['amazon_evidence']);
        self::assertStringContainsString('DEC-009', $synthesis['gross_weight']['amazon_evidence']);
    }

    #[Test]
    public function number_of_items_is_not_treated_as_a_package_quantity_rename(): void
    {
        $mapping = $this->amazonMapping($this->rows('docs/data/canonical_product_field_mappings.csv'), 'package_quantity');

        self::assertSame('number_of_items', $mapping['external_field']);
        self::assertSame('transformed', $mapping['mapping_type']);
        self::assertSame('amazon_number_of_items_packaging_count_semantics_unresolved', $mapping['transformation']);
        self::assertSame('partially_verified', $mapping['verification_status']);

        $coverage = $this->index($this->rows('docs/data/canonical-coverage/amazon.csv'), 'external_key');
        self::assertSame('DEFERRED_REVIEW', $coverage['number_of_items']['review_status']);
        self::assertStringContainsString('amazon_package_quantity_semantics', $coverage['number_of_items']['decision_reference']);
    }

    #[Test]
    public function proven_list_price_and_bullet_point_mappings_remain_verified(): void
    {
        $mappings = $this->rows('docs/data/canonical_product_field_mappings.csv');
        self::assertSame('verified', $this->amazonMapping($mappings, 'recommended_retail_price')['verification_status']);
        self::assertSame('verified', $this->amazonMapping($mappings, 'product_highlights')['verification_status']);

        $coverage = $this->index($this->rows('docs/data/canonical-coverage/amazon.csv'), 'external_key');
        self::assertSame('PROVIDER_VERIFIED', $coverage['list_price']['review_status']);
        self::assertSame('PROVIDER_VERIFIED', $coverage['bullet_point']['review_status']);
        self::assertSame('DEFERRED_REVIEW', $coverage['special_feature']['review_status']);
    }

    #[Test]
    public function amazon_product_type_applicability_remains_evidence_scoped(): void
    {
        $applicability = $this->rows('docs/data/canonical_product_field_applicability.csv');
        $amazon = array_values(array_filter($applicability, fn (array $row): bool => $row['channel_or_state'] === 'amazon'));

        self::assertCount(20, $amazon);
        foreach ($amazon as $row) {
            self::assertSame('product_type', $row['context_type']);
            self::assertSame('LUGGAGE', $row['product_type_or_state']);
            self::assertSame('ATVPDKIKX0DER', $row['market_or_state']);
            self::assertSame('ptd-2020-09-01-luggage-public-example', $row['schema_version']);
        }
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
    private function amazonMapping(array $rows, string $internalCode): array
    {
        $matches = array_values(array_filter($rows, fn (array $row): bool => $row['channel'] === 'amazon'
            && $row['internal_code'] === $internalCode
            && $row['channel_schema_version'] === 'ptd-2020-09-01-luggage-public-example'));
        self::assertCount(1, $matches, $internalCode);

        return $matches[0];
    }
}
