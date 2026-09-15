<?php

namespace Tests\Unit;

use App\Support\CanonicalRegistry\CanonicalRegistryReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanonicalRegistryReaderTest extends TestCase
{
    #[Test]
    public function canonical_registry_reader_resolves_from_container_without_explicit_data_path(): void
    {
        $reader = app(CanonicalRegistryReader::class);

        $fields = $reader->fields();

        $this->assertNotEmpty($fields);
        $this->assertArrayHasKey('internal_code', $fields[0]);
    }

    #[Test]
    public function canonical_registry_reader_instantiates_without_arguments(): void
    {
        $reader = new CanonicalRegistryReader;

        $fields = $reader->fields();

        $this->assertNotEmpty($fields);
        $this->assertSame(
            config('canonical_registry.data_path'),
            (new \ReflectionProperty(CanonicalRegistryReader::class, 'dataPath'))
                ->getValue($reader),
        );
    }
}
