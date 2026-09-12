<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\AdobeProductAttributeProviderRegistry;
use Tests\TestCase;

class AdobeProductAttributeProviderRegistryTest extends TestCase
{
    public function test_positive_registry_entries_have_durable_frozen_evidence_refs(): void
    {
        $path = resource_path('connector-registry/adobe_commerce_product_attribute_registry.json');
        $registry = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('adobe.product_attribute_registry.v1', $registry['schema_version']);
        $this->assertSame('adobe_commerce', $registry['provider']);
        $this->assertSame('product_attribute', $registry['surface']);
        $this->assertNotEmpty($registry['attributes']);

        foreach ($registry['attributes'] as $key => $entry) {
            $this->assertNotEmpty($entry['evidence_refs'] ?? [], $key);
            $keyIsProven = false;
            foreach ($entry['evidence_refs'] as $ref) {
                $this->assertFileExists(base_path($ref), $key.' -> '.$ref);
                $this->assertContains($ref, $registry['source_contracts']);

                $evidence = file_get_contents(base_path($ref));
                $this->assertIsString($evidence, $key.' -> '.$ref);
                if (str_contains($evidence, $key)
                    || ($key === 'category_ids' && str_contains($evidence, 'category_relation_to_adobe_category_ids'))) {
                    $keyIsProven = true;
                }
            }
            $this->assertTrue($keyIsProven, $key.' has no literal or transformation evidence in its declared refs.');
        }
    }

    public function test_open_research_queue_keys_cannot_be_positive_provider_registry_entries(): void
    {
        $registry = app(AdobeProductAttributeProviderRegistry::class);

        foreach ([
            'quantity_and_stock_status',
            'old_id',
            'custom_layout_update_file',
            'tier_price',
            'custom_layout',
        ] as $key) {
            $this->assertNull($registry->find($key), $key);
        }
    }
}
