<?php

namespace Tests\Unit\CanonicalCoverage;

use App\Support\CanonicalCoverage\BigCommerceCoverage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BigCommerceCoverageTest extends TestCase
{
    #[Test]
    public function committed_provider_slice_has_complete_reproducible_coverage(): void
    {
        $metrics = (new BigCommerceCoverage)->validate(dirname(__DIR__, 3));

        $this->assertSame(136, $metrics['manifest_rows']);
        $this->assertSame(136, $metrics['coverage_rows']);
        $this->assertSame(1.0, $metrics['coverage_ratio']);
        $this->assertSame(1.0, $metrics['classification_ratio']);
        $this->assertSame(1.0, $metrics['concept_link_ratio']);
        $this->assertSame(1.0, $metrics['terminal_rationale_ratio']);
        $this->assertSame(0, $metrics['silent_drop_count']);
    }

    #[Test]
    public function generation_is_byte_for_byte_deterministic(): void
    {
        $service = new BigCommerceCoverage;
        $root = dirname(__DIR__, 3);
        $service->validate($root);
        $before = array_map(fn ($file) => hash_file('sha256', "$root/$file"), [BigCommerceCoverage::MANIFEST, BigCommerceCoverage::COVERAGE, BigCommerceCoverage::CONCEPTS]);
        $service->generate($root);
        $after = array_map(fn ($file) => hash_file('sha256', "$root/$file"), [BigCommerceCoverage::MANIFEST, BigCommerceCoverage::COVERAGE, BigCommerceCoverage::CONCEPTS]);

        $this->assertSame($before, $after);
    }
}
