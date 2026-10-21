<?php

namespace Tests\Unit;

use App\Support\CanonicalRegistry\CanonicalGovernanceReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanonicalGovernanceReaderTest extends TestCase
{
    #[Test]
    public function canonical_governance_reader_resolves_from_container_without_explicit_paths(): void
    {
        $reader = app(CanonicalGovernanceReader::class);

        $decisions = $reader->listDecisions();

        $this->assertNotEmpty($decisions);
        $this->assertArrayHasKey('id', $decisions[0]);
        $this->assertArrayHasKey('type', $decisions[0]);
        $this->assertArrayHasKey('title', $decisions[0]);
    }
}
