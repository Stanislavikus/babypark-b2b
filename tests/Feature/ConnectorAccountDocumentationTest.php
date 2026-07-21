<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorAccountDocumentationTest extends TestCase
{
    #[Test]
    public function domain_model_documents_connector_account_boundary(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### ConnectorAccount (Proposed — Task 4B-0 Stop-and-Amend)', $content);
        $this->assertStringContainsString('workspace-owned', $content);
        $this->assertStringContainsString('encrypted:array', $content);
        $this->assertStringContainsString('does **not** contain', $content);
    }

    #[Test]
    public function domain_model_documents_connection_check_and_discovery_history(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### ConnectorConnectionCheck (Proposed)', $content);
        $this->assertStringContainsString('### ConnectorDiscoveryRun (Proposed)', $content);
        $this->assertStringContainsString('append-only', $content);
    }

    #[Test]
    public function domain_model_separates_snapshot_and_diff(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### ConnectorSchemaSnapshot (Proposed)', $content);
        $this->assertStringContainsString('### ConnectorSchemaDiff / ConnectorSchemaDiffItem (Proposed)', $content);
        $this->assertStringContainsString('**No** `previous_value` / `current_value`', $content);
    }

    #[Test]
    public function domain_model_documents_dual_axis_error_classification(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### Dual-axis error classification (Proposed)', $content);
        $this->assertStringContainsString('authentication', $content);
        $this->assertStringContainsString('authorization', $content);
        $this->assertStringContainsString('user_action_required', $content);
        $this->assertStringContainsString('automatic_retry', $content);
    }

    #[Test]
    public function domain_model_keeps_sync_log_legacy(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('#### Boundary vs legacy `SyncLog`', $content);
        $this->assertStringContainsString('does not** extend or reuse `SyncLog`', $content);
    }

    #[Test]
    public function domain_model_keeps_field_mapping_in_task_4c(): void
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        $this->assertStringContainsString('### Task 4B vs Task 4C boundary (Proposed)', $content);
        $this->assertStringContainsString('Discovery must **not** auto-create', $content);
        $this->assertStringContainsString('Task 4C', $content);
    }

    #[Test]
    public function ai_working_agreement_requires_visual_contract_before_persistence(): void
    {
        $content = File::get(base_path('docs/05-AI_WORKING_AGREEMENT.md'));

        $this->assertStringContainsString('### Visual Contract Before Persistence for Complex Operational Features', $content);
        $this->assertStringContainsString('A backend-only delivery is not acceptable', $content);
    }

    #[Test]
    public function ui_design_system_documents_connection_and_discovery_states(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('## Operational Connection Pattern (reusable)', $content);
        $this->assertStringContainsString('Потребує уваги', $content);
        $this->assertStringContainsString('401', $content);
        $this->assertStringContainsString('403', $content);
    }

    #[Test]
    public function ui_design_system_documents_current_state_vs_history(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('### Current state vs history', $content);
        $this->assertStringContainsString('current projection', $content);
        $this->assertStringContainsString('Activity history', $content);
    }

    #[Test]
    public function implementation_gaps_documents_task_4b0_4b1_4b2_4c_sequence(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**4B-0**', $content);
        $this->assertStringContainsString('**4B-1**', $content);
        $this->assertStringContainsString('**4B-2**', $content);
        $this->assertStringContainsString('**4C**', $content);
    }

    #[Test]
    public function implementation_gaps_keeps_gap_006_open(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('GAP-006 stays Open', $content);
        $this->assertStringContainsString('**Status:** Open', $content);
    }

    #[Test]
    public function prototype_desktop_and_mobile_screenshots_are_distinct(): void
    {
        $dir = base_path('docs/prototypes/task-4b0-connector-account/screenshots');
        $pairs = [
            ['02-settings-desktop-1440.png', '02-settings-mobile-375.png'],
            ['03-check-error-auth-desktop-1440.png', '03-check-error-auth-mobile-375.png'],
            ['04-discovery-desktop-1440.png', '04-discovery-mobile-375.png'],
            ['06-history-desktop-1440.png', '06-history-mobile-375.png'],
        ];

        foreach ($pairs as [$desktop, $mobile]) {
            $desktopPath = $dir.'/'.$desktop;
            $mobilePath = $dir.'/'.$mobile;

            $this->assertFileExists($desktopPath, "Missing desktop screenshot: {$desktop}");
            $this->assertFileExists($mobilePath, "Missing mobile screenshot: {$mobile}");

            $desktopHash = hash_file('sha256', $desktopPath);
            $mobileHash = hash_file('sha256', $mobilePath);

            $this->assertNotSame(
                $desktopHash,
                $mobileHash,
                "{$desktop} and {$mobile} are byte-identical (not a real distinct capture)"
            );
        }
    }
}
