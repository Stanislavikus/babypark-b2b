<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Stage3ER1ImplementationTruthDocumentationTest extends TestCase
{
    #[Test]
    public function implementation_gaps_documents_moduleless_simple_write_consumption_without_live_truth_flip(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**Stage 3E-R1 internal read foundation is implemented**', $content);
        $this->assertStringContainsString('**standard moduleless trusted simple Product WRITE consumption is implemented internally**', $content);
        $this->assertStringContainsString('**Safe Sync simple WRITE remains implemented as an optional Enhanced Safety primitive and is no longer the standard-path dependency**', $content);
        $this->assertStringContainsString('**Stage 3E-R2b-1 merchant-confirmed ENTITY TRUST review/confirm backend is implemented**', $content);
        $this->assertStringContainsString('**disposable validation harness is implemented internally as a validation-only Laravel control plane**', $content);
        $this->assertStringContainsString('real-target core Simple price WRITE/restore step is verified; remaining field-by-field validation', $content);
        $this->assertStringContainsString('support remains **false**', $content);
    }

    #[Test]
    public function atlas_tracks_optional_safe_sync_and_moduleless_stock_simple_write_while_live_support_stays_false(): void
    {
        $content = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('Stage 3E Magento Safe Sync enhanced-safety runtime', $content);
        $this->assertStringContainsString('IMPLEMENTED (internal optional primitive; support false; not standard-path prerequisite)', $content);
        $this->assertStringContainsString('Magento V1 moduleless stock simple trusted WRITE', $content);
        $this->assertStringContainsString('IMPLEMENTED + CORE REAL-TARGET VERIFIED (support false; field-by-field certification pending)', $content);
        $this->assertStringContainsString('Stage 3E disposable validation harness', $content);
        $this->assertStringContainsString('IMPLEMENTED (internal; validation-only; support false; no real-target certification executed)', $content);
        $this->assertStringContainsString('integrations/magento-safe-sync/', $content);
        $this->assertStringContainsString('AdobeSafeSyncClient.php', $content);
        $this->assertStringContainsString('Adobe Products/Export/Live support truth', $content);
        $this->assertStringContainsString('CONFIRMED ABSENT (public)', $content);
    }
}
