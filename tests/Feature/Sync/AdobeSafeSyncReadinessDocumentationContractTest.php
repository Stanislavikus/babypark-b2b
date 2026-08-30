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
        $this->assertStringContainsString("Simple Product WRITE readiness also requires a comparable semantic module\nversion at or above", $domain);
        $this->assertStringContainsString('Safe Sync component readiness + certification package', $atlas);
    }

    #[Test]
    public function decision_6_records_php_adobe_certification_matrix(): void
    {
        $domain = file_get_contents(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('| **PRIMARY** | 2.4.9 | 8.5 |', $domain);
        $this->assertStringContainsString('| **UPGRADE-COMPATIBILITY ONLY — not a production support claim** | 2.4.9 | 8.4 |', $domain);
        $this->assertStringContainsString('| **PREVIOUS CERTIFIED TARGET** | 2.4.8-p5 | 8.4 |', $domain);
        $this->assertStringContainsString('| **OUT OF V1 CERTIFICATION** | — | 8.3 |', $domain);

        $this->assertStringContainsString('PHP 8.5 is the 2.4.9 production target', $domain);
        $this->assertStringContainsString('PHP 8.4 on 2.4.9 is upgrade compatibility only', $domain);
        $this->assertStringContainsString('This label correction does not broaden or otherwise change the Safe Sync Composer constraints', $domain);
        $this->assertStringContainsString('PHP 8.3 is **OUT of V1 certification**', $domain);

        $this->assertStringNotContainsString('| **SUPPORTED COMPATIBILITY** | 2.4.9 | 8.4 |', $domain);
        $this->assertStringNotContainsString('PHP 8.4 **IS supported** on Adobe Commerce 2.4.9', $domain);
    }
}
