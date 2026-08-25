<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Stage3ER1ImplementationTruthDocumentationTest extends TestCase
{
    #[Test]
    public function implementation_gaps_documents_internal_read_and_isolated_write_foundations_without_live_truth_flip(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**Stage 3E-R1 internal read foundation is implemented**', $content);
        $this->assertStringContainsString('**isolated entity-bound simple Product WRITE foundation is implemented internally**', $content);
        $this->assertStringContainsString('**Stage 3E-R2b-1 merchant-confirmed ENTITY TRUST review/confirm backend is implemented**', $content);
        $this->assertStringContainsString('**disposable validation harness is implemented internally as a validation-only Laravel control plane**', $content);
        $this->assertStringContainsString('real-target certification step 4, decisions 5–9, Live consumption, and support flip remain **pending**', $content);
        $this->assertStringContainsString('support remains **false**', $content);
    }

    #[Test]
    public function atlas_tracks_internal_safe_sync_read_and_isolated_write_foundation_while_live_support_stays_false(): void
    {
        $content = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('Stage 3E Magento Safe Sync read + isolated simple write foundation', $content);
        $this->assertStringContainsString('IMPLEMENTED (internal; support false; not consumed by Live; not real-target certified)', $content);
        $this->assertStringContainsString('Stage 3E disposable validation harness', $content);
        $this->assertStringContainsString('IMPLEMENTED (internal; validation-only; support false; no real-target certification executed)', $content);
        $this->assertStringContainsString('integrations/magento-safe-sync/', $content);
        $this->assertStringContainsString('AdobeSafeSyncClient.php', $content);
        $this->assertStringContainsString('Adobe Products/Export/Live support truth', $content);
        $this->assertStringContainsString('CONFIRMED ABSENT (public)', $content);
    }
}
