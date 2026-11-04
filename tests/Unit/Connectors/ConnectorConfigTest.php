<?php

namespace Tests\Unit\Connectors;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorConfigTest extends TestCase
{
    #[Test]
    public function connectors_config_contains_only_scalars_and_class_strings(): void
    {
        /** @var array<string, mixed> $config */
        $config = require config_path('connectors.php');

        $this->assertConfigLeavesAreScalarsOrClassStrings($config);
    }

    /**
     * @param  array<mixed>|scalar|null  $value
     */
    private function assertConfigLeavesAreScalarsOrClassStrings(mixed $value, string $path = 'connectors'): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->assertConfigLeavesAreScalarsOrClassStrings($child, $path.'.'.$key);
            }

            return;
        }

        $this->assertTrue(
            is_scalar($value),
            sprintf('Config leaf at [%s] must be scalar, got %s.', $path, get_debug_type($value)),
        );
    }
}
