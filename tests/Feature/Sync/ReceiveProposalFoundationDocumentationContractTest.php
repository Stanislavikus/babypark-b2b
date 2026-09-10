<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiveProposalFoundationDocumentationContractTest extends TestCase
{
    #[Test]
    public function domain_model_freezes_receive_proposal_and_apply_revalidation_contract(): void
    {
        $section = $this->receiveFoundationSection();

        $this->assertStringContainsString('zero-mutation proposal/diff', $section);
        $this->assertStringContainsString('Equal may silently no-op, but destructive replacement or clear requires explicit action.', $section);
        $this->assertStringContainsString('Receive proposal is short-lived, server-authoritative, and transient', str_replace('A per-item or per-operation ', '', $section));
        $this->assertStringContainsString('Apply-Time Revalidation is Mandatory', $section);
    }

    #[Test]
    public function atlas_truth_is_granular_for_receive_foundation_read_and_apply(): void
    {
        $atlas = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('| Receive proposal/planner foundation | IMPLEMENTED |', $atlas);
        $this->assertStringContainsString('| Adobe Products Receive name proposal orchestration | IMPLEMENTED (internal; zero-mutation; no merchant entrypoint; no Apply) |', $atlas);
        $this->assertStringContainsString('| Receive connector read/orchestration | PARTIAL |', $atlas);
        $this->assertStringContainsString('Normal `ConnectorSyncOperationSupport` / `SyncConfigurationService` admission still does **not** advertise or admit Adobe Products/Import from this internal primitive.', $atlas);
        $this->assertStringContainsString('Import support must **not** be inferred from the existence of this internal Adobe service because normal Adobe Products/Import admission remains disabled.', $atlas);
        $this->assertStringContainsString('| Receive Apply runtime | CONFIRMED ABSENT |', $atlas);
        $this->assertStringNotContainsString('| Receive / Import runtime | CONFIRMED ABSENT |', $atlas);
    }

    /**
     * @return non-empty-string
     */
    private function receiveFoundationSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/## Receive \/ Import Foundation Contract \(Resolved\)\n\n(.*?)(?=\n## )/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Receive / Import Foundation Contract.');
        }

        return $matches[1];
    }
}
