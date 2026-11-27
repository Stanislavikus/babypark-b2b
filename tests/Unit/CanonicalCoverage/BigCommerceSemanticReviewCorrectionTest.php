<?php

namespace Tests\Unit\CanonicalCoverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BigCommerceSemanticReviewCorrectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
    }

    #[Test]
    public function product_bound_dimensions_use_product_surface(): void
    {
        $mappings = $this->rows('docs/data/canonical_product_field_mappings.csv');
        foreach (['depth_mm' => 'Product.depth', 'width_mm' => 'Product.width', 'height_mm' => 'Product.height'] as $code => $external) {
            $mapping = $this->bigCommerceMapping($mappings, $code);
            self::assertSame($external, $mapping['external_field']);
            self::assertSame('verified', $mapping['verification_status']);
        }
    }

    #[Test]
    public function weight_relation_is_product_aligned_but_fail_closed_on_packaging_semantics(): void
    {
        $mapping = $this->bigCommerceMapping($this->rows('docs/data/canonical_product_field_mappings.csv'), 'net_weight');

        self::assertSame('Product.weight', $mapping['external_field']);
        self::assertSame('partially_verified', $mapping['verification_status']);

        $synthesis = $this->index($this->rows('docs/data/cross_platform_product_field_synthesis.csv'), 'concept_key');
        self::assertStringContainsString('shipping/store weight', $synthesis['net_weight']['bigcommerce_evidence']);
        self::assertStringContainsString('packaging', $synthesis['net_weight']['bigcommerce_evidence']);
    }

    #[Test]
    public function variant_bound_price_and_identifier_mappings_remain_variant_scoped(): void
    {
        $mappings = $this->rows('docs/data/canonical_product_field_mappings.csv');
        foreach (['sku' => 'ProductVariant.sku', 'price' => 'ProductVariant.price', 'recommended_retail_price' => 'ProductVariant.retail_price'] as $code => $external) {
            $mapping = $this->bigCommerceMapping($mappings, $code);
            self::assertSame($external, $mapping['external_field']);
            self::assertSame('verified', $mapping['verification_status']);
        }
    }

    #[Test]
    public function rrp_mapping_has_direct_bigcommerce_msrp_evidence(): void
    {
        $sources = $this->rows('docs/data/canonical_product_field_sources.csv');
        $matches = array_values(array_filter($sources, fn (array $row): bool => $row['evidence_subject_key'] === 'mapping:bigcommerce:recommended_retail_price:ProductVariant.retail_price:a084:v3-openapi-2026-09-07'));

        self::assertGreaterThanOrEqual(2, count($matches));
        self::assertTrue((bool) array_filter($matches, fn (array $row): bool => str_contains($row['evidence_note'], 'manufacturer suggested retail price')));

        $synthesis = $this->index($this->rows('docs/data/cross_platform_product_field_synthesis.csv'), 'concept_key');
        self::assertStringContainsString('manufacturer suggested retail price', $synthesis['recommended_retail_price']['bigcommerce_evidence']);
    }

    #[Test]
    public function min_order_mapping_stays_verified_while_condition_remains_unmapped(): void
    {
        $mappings = $this->rows('docs/data/canonical_product_field_mappings.csv');
        $min = $this->bigCommerceMapping($mappings, 'min_order_quantity');
        self::assertSame('Product.order_quantity_minimum', $min['external_field']);
        self::assertSame('verified', $min['verification_status']);

        self::assertSame([], array_values(array_filter($mappings, fn (array $row): bool => $row['channel'] === 'bigcommerce' && $row['internal_code'] === 'condition')));
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
    private function bigCommerceMapping(array $rows, string $internalCode): array
    {
        $matches = array_values(array_filter($rows, fn (array $row): bool => $row['channel'] === 'bigcommerce'
            && $row['internal_code'] === $internalCode
            && $row['channel_schema_version'] === 'v3-openapi-2026-09-07'));
        self::assertCount(1, $matches, $internalCode);

        return $matches[0];
    }
}
