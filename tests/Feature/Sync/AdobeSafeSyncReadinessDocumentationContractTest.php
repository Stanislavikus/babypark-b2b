<?php

namespace Tests\Feature\Sync;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeSafeSyncReadinessDocumentationContractTest extends TestCase
{
    #[Test]
    public function decision_six_and_stateless_readiness_contract_are_current(): void
    {
        $domain = file_get_contents(base_path('docs/03-DOMAIN_MODEL.md'));
        $atlas = file_get_contents(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('UPGRADE-COMPATIBILITY ONLY — not a production support claim', $domain);
        $this->assertStringContainsString('PREVIOUS CERTIFIED TARGET', $domain);
        $this->assertStringContainsString('Safe Sync component readiness (Resolved — 2026-08-30)', $domain);
        $this->assertStringContainsString('no readiness', $domain);
        $this->assertStringContainsString('table or account projection is introduced', $domain);
        $this->assertStringContainsString('Safe Sync component readiness + certification package', $atlas);
    }
}
