<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CanonicalRuntimeAlignmentDocumentationContractTest extends TestCase
{
    #[Test]
    public function canonical_registry_seed_section_includes_v5_seeded_fields_as_seeded(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root.'/docs/CANONICAL_PRODUCT_FIELD_REGISTRY.md');
        $this->assertIsString($content);

        foreach ([
            '`condition`',
            '`short_description`',
            '`material`',
            '`country_of_origin`',
            '`manufacturer`',
            '`model`',
            '`compatibility`',
            '`battery_type`',
        ] as $needle) {
            $this->assertStringContainsString($needle, $content);
        }

        $this->assertStringNotContainsString('Not in seeder; Google required for ads', $content);
        $this->assertStringNotContainsString('Attribute Dictionary seed list; not in FieldDefinitionSeeder', $content);
    }

    #[Test]
    public function attribute_dictionary_brand_and_status_wording_match_frozen_semantics(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root.'/docs/02-ATTRIBUTE_DICTIONARY.md');
        $this->assertIsString($content);

        $this->assertStringNotContainsString('Brand or manufacturer name', $content);
        $this->assertStringContainsString('Commercial brand name', $content);

        $this->assertStringNotContainsString('draft, active, archived', $content);
        $this->assertStringContainsString('products.is_active', $content);
        $this->assertStringContainsString('publication/visibility/listing', $content);
    }

    #[Test]
    public function attribute_dictionary_uses_meta_title_and_meta_description_terms(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root.'/docs/02-ATTRIBUTE_DICTIONARY.md');
        $this->assertIsString($content);

        $this->assertStringContainsString('`meta_title` / `meta_description`', $content);
        $this->assertStringNotContainsString('`seo_title` / `seo_description`', $content);
    }

    #[Test]
    public function domain_model_no_longer_claims_merchant_type_tags_unimplemented(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root.'/docs/03-DOMAIN_MODEL.md');
        $this->assertIsString($content);

        $this->assertStringNotContainsString('This is a planning decision, not yet implemented', $content);
        $this->assertStringContainsString('Implemented as `products.merchant_type`', $content);
        $this->assertStringContainsString('`tags` table + `product_tag` pivot', $content);
    }

    #[Test]
    public function canonical_csv_brand_and_tags_rows_match_runtime_truth_boundaries(): void
    {
        $root = dirname(__DIR__, 2);
        $fields = $this->readCsv($root.'/docs/data/canonical_product_fields.csv');
        $rows = array_column($fields, null, 'internal_code');

        $this->assertStringNotContainsString(
            'Brand or manufacturer name',
            $rows['brand']['description'],
        );

        $this->assertSame('relation', $rows['tags']['implementation_kind']);
        $this->assertSame('relation_not_field', $rows['tags']['recommended_action']);
        $this->assertSame('not_applicable', $rows['tags']['data_type_or_state']);
        $this->assertSame('true', $rows['tags']['is_multi_value']);
    }

    #[Test]
    public function canonical_csv_preserves_media_domain_boundary_for_images(): void
    {
        $root = dirname(__DIR__, 2);
        $fields = $this->readCsv($root.'/docs/data/canonical_product_fields.csv');
        $rows = array_column($fields, null, 'internal_code');

        foreach (['image', 'images'] as $code) {
            $this->assertSame('media_domain', $rows[$code]['implementation_kind'], $code);
            $this->assertSame('covered_by_existing_domain', $rows[$code]['recommended_action'], $code);
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        $this->assertNotFalse($handle, $path);
        $header = fgetcsv($handle, escape: '\\');
        $this->assertIsArray($header);

        $rows = [];
        while (($data = fgetcsv($handle, escape: '\\')) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }
            $this->assertCount(count($header), $data, $path);
            $row = array_combine($header, $data);
            $this->assertIsArray($row);
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
