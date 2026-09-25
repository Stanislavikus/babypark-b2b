<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Stage3ER1ImplementationTruthDocumentationTest extends TestCase
{
    #[Test]
    public function implementation_gaps_documents_moduleless_export_live_truth_after_real_target_flip(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString(
            'standard trusted Product UPDATE now uses the moduleless stock REST path with MerchantConfirmed identity',
            $content,
        );
        $this->assertStringContainsString(
            'bounded Safe Sync remains optional Enhanced Safety rather than a standard-path prerequisite',
            $content,
        );
        $this->assertStringContainsString(
            'Magento Products/Export/Live truth flip is **Done and real-target E2E certified 2026-09-19**',
            $content,
        );
        $this->assertStringContainsString(
            'Adobe Products/Export/Live advertised support is **true**',
            $content,
        );
        $this->assertStringContainsString(
            'Products/Import/Live remains **false**',
            $content,
        );
        $this->assertStringContainsString(
            'Product CREATE remains unsupported',
            $content,
        );
    }

    #[Test]
    public function atlas_tracks_optional_safe_sync_and_public_moduleless_export_live(): void
    {
        $content = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('Stage 3E Magento Safe Sync enhanced-safety runtime', $content);
        $this->assertStringContainsString(
            'IMPLEMENTED (internal optional primitive; support false; not standard-path prerequisite)',
            $content,
        );
        $this->assertStringContainsString('Magento V1 moduleless stock simple trusted WRITE', $content);
        $this->assertStringContainsString(
            'IMPLEMENTED + REAL-TARGET VERIFIED + PUBLIC IN BOUNDED EXPORT LIVE V1',
            $content,
        );
        $this->assertStringContainsString('Stage 3E disposable validation harness', $content);
        $this->assertStringContainsString(
            'IMPLEMENTED (internal; validation-only; support false; no real-target certification executed)',
            $content,
        );
        $this->assertStringContainsString('integrations/magento-safe-sync/', $content);
        $this->assertStringContainsString('AdobeSafeSyncClient.php', $content);
        $this->assertStringContainsString(
            'Adobe Products/Export/Live support truth | SUPPORTED (public) — [Resolved 2026-09-19; Simple CREATE extended 2026-09-24; Configurable CREATE/resume extended 2026-09-25]',
            $content,
        );
        $this->assertStringContainsString(
            'Live runtime readiness | IMPLEMENTED + REAL-TARGET VERIFIED (2026-09-19)',
            $content,
        );
    }
}
