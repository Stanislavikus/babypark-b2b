<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Stage3ER1ImplementationTruthDocumentationTest extends TestCase
{
    #[Test]
    public function implementation_gaps_documents_internal_read_foundation_without_live_truth_flip(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**Stage 3E-R1 internal read foundation is implemented**', $content);
        $this->assertStringContainsString('ERL trust/link runtime, consequential mutation, validation harness rebuild, and support flip remain **pending**', $content);
        $this->assertStringContainsString('support remains **false**', $content);
    }

    #[Test]
    public function atlas_tracks_internal_safe_sync_read_foundation_while_live_support_stays_false(): void
    {
        $content = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('| Stage 3E-R1 Magento Safe Sync read foundation | IMPLEMENTED (internal; support false) |', $content);
        $this->assertStringContainsString('integrations/magento-safe-sync/', $content);
        $this->assertStringContainsString('AdobeSafeSyncClient.php', $content);
        $this->assertStringContainsString('| Adobe Products/Export/Live support truth | CONFIRMED ABSENT (public) |', $content);
    }
}
