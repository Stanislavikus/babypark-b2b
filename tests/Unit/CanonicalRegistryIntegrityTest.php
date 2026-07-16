<?php

namespace Tests\Unit;

use App\Support\CanonicalRegistry\CanonicalRegistryValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanonicalRegistryIntegrityTest extends TestCase
{
    #[Test]
    public function real_registry_data_passes_structural_validation(): void
    {
        $validator = new CanonicalRegistryValidator(
            base_path('docs/data'),
            base_path('docs/CANONICAL_PRODUCT_FIELD_REGISTRY.md'),
        );

        $result = $validator->validate();

        $this->assertSame(
            [],
            $result['errors'],
            "Registry structural errors:\n".implode("\n", $result['errors']),
        );

        $expectedMetricKeys = [
            'fields', 'mappings', 'aliases', 'sources', 'options',
            'option_mappings', 'constraints', 'applicability',
        ];

        foreach ($expectedMetricKeys as $key) {
            $this->assertArrayHasKey($key, $result['metrics']);
            $this->assertGreaterThan(0, $result['metrics'][$key], "Expected non-zero row count for {$key}");
        }
    }
}
