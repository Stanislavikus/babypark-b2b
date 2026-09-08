<?php

namespace Tests\Unit\CanonicalCoverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CanonicalFinalArbitrationRegressionTest extends TestCase
{
    #[Test]
    public function category_keeps_merchant_relation_without_taxonomy_equivalence(): void
    {
        $row = $this->synthesis()['category'];

        self::assertSame('Category', $row['platform_owner_candidate']);
        self::assertSame('relation', $row['representation_candidate']);
        self::assertSame('KEEP_PLATFORM_CANONICAL', $row['canonical_decision']);
        self::assertStringContainsString('connector mapping required', $row['adobe_evidence']);
        self::assertStringContainsString('not Merchant Category equivalence', $row['shopify_evidence']);
        self::assertStringContainsString('equivalence remains open', $row['google_evidence']);
        self::assertStringContainsString('connector mapping required', $row['bigcommerce_evidence']);
        self::assertStringContainsString('tracked in separate provider-only rows', $row['amazon_evidence']);
    }

    #[Test]
    public function url_keeps_absolute_semantics_without_slug_or_landing_url_equivalence(): void
    {
        $row = $this->synthesis()['url'];

        self::assertSame('Product', $row['platform_owner_candidate']);
        self::assertSame('core_model_property', $row['representation_candidate']);
        self::assertSame('KEEP_PLATFORM_CANONICAL', $row['canonical_decision']);
        self::assertStringContainsString('not absolute canonical URL equivalence', $row['adobe_evidence']);
        self::assertStringContainsString('handle is slug identity', $row['shopify_evidence']);
        self::assertStringContainsString('equivalence remains provider/context-specific', $row['google_evidence']);
        self::assertStringContainsString('transformation/context required', $row['bigcommerce_evidence']);
    }

    #[Test]
    public function project_contract_explicitly_separates_category_taxonomy_and_url_slug_boundaries(): void
    {
        $root = dirname(__DIR__, 3);
        $registry = file_get_contents($root.'/docs/CANONICAL_PRODUCT_FIELD_REGISTRY.md');
        $domain = file_get_contents($root.'/docs/03-DOMAIN_MODEL.md');

        self::assertIsString($registry);
        self::assertIsString($domain);
        self::assertStringContainsString('Workspace Category tree, Google/Shopify/Amazon/Rozetka taxonomies remain outside this field registry.', $registry);
        self::assertStringContainsString('primary absolute customer-facing product page URL', $registry);
        self::assertStringContainsString('Shopify handle', $registry);
        self::assertStringContainsString('Adobe Commerce url_key', $registry);
        self::assertStringContainsString('Merchant/Catalogue Category', $domain);
        self::assertStringContainsString('Standard Category', $domain);
    }

    /** @return array<string, array<string, string>> */
    private function synthesis(): array
    {
        $path = dirname(__DIR__, 3).'/docs/data/cross_platform_product_field_synthesis.csv';
        $handle = fopen($path, 'rb');
        self::assertNotFalse($handle);

        $header = fgetcsv($handle);
        self::assertIsArray($header);

        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            self::assertCount(count($header), $values);
            $row = array_combine($header, $values);
            self::assertIsArray($row);
            $rows[$row['concept_key']] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
