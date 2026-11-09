<?php

namespace Tests\Unit;

use App\Support\CanonicalRegistry\CanonicalRegistryReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShopifyCanonicalSemanticCorrectionTest extends TestCase
{
    #[Test]
    public function unsafe_shopify_relationships_are_not_verified_mapping_knowledge(): void
    {
        $mappings = collect(app(CanonicalRegistryReader::class)->mappings())
            ->where('channel', 'shopify');

        foreach ([
            'vendor',
            'variants.barcode',
            'Product.status',
            'ProductVariant.barcode',
            'InventoryItem.countryCodeOfOrigin',
            'InventoryItem.measurement.weight',
        ] as $externalField) {
            $mapping = $mappings->firstWhere('external_field', $externalField);

            $this->assertNotNull($mapping, $externalField);
            $this->assertSame('partially_verified', $mapping['verification_status'], $externalField);
        }

        $vendor = $mappings->firstWhere('external_field', 'vendor');
        $this->assertNotSame('renamed', $vendor['mapping_type']);

        $legacyBarcode = $mappings->firstWhere('external_field', 'variants.barcode');
        $this->assertNotSame('a004', $legacyBarcode['applicability_id']);
    }

    #[Test]
    public function retained_verified_legacy_shopify_rows_have_primary_source_provenance(): void
    {
        $reader = app(CanonicalRegistryReader::class);
        $sourceSubjects = array_fill_keys(array_column($reader->sources(), 'evidence_subject_key'), true);

        foreach (['body_html', 'variants.sku'] as $externalField) {
            $mapping = collect($reader->mappings())
                ->where('channel', 'shopify')
                ->where('external_field', $externalField)
                ->where('verification_status', 'verified')
                ->first();

            $this->assertNotNull($mapping, $externalField);
            $this->assertArrayHasKey($mapping['evidence_subject_key'], $sourceSubjects, $externalField);
        }
    }
}
