<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImplementationGapsTest extends TestCase
{
    #[Test]
    public function gap_006_contains_task_4b_ui_handoff(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('GAP-006 — Connector / Import / FieldMapping infrastructure absent', $content);
        $this->assertStringContainsString('**Task 4B UI handoff:**', $content);
        $this->assertStringContainsString('Task 4B must align all Eloquent list filters it materially touches with', $content);
        $this->assertStringContainsString('06-UI_DESIGN_SYSTEM.md', $content);
    }

    #[Test]
    public function gap_006_keeps_connector_account_credentials_out_of_connector_definition(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString(
            'Workspace API credentials, store/account base URLs, connection status,',
            $content
        );
        $this->assertStringContainsString('`ConnectorAccount`; do not store them on `ConnectorDefinition`.', $content);
        $this->assertStringContainsString('last sync and live account discovery belong to workspace-owned', $content);
    }

    #[Test]
    public function gap_006_keeps_field_mapping_runtime_in_task_4c(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString(
            'Automatic field-match suggestions, confidence handling, persistence of',
            $content
        );
        $this->assertStringContainsString('to the subsequent FieldMapping workflow (Task 4C), not to', $content);
        $this->assertStringContainsString('ConnectorDefinition metadata.', $content);
    }

    #[Test]
    public function gap_025_reflects_implemented_sync_configuration_and_field_mapping_persistence(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('`SyncConfiguration` persistence and domain write path — **implemented**', $content);
        $this->assertStringContainsString('`FieldMapping` persistence, manual confirmation', $content);
        $this->assertStringContainsString('Canonical suggestion/read-model provider — **implemented**', $content);
        $this->assertStringContainsString('Do not claim `SyncConfiguration` or `FieldMapping` are unimplemented', $content);
        $this->assertStringContainsString('Layer B mapping UI (4C-1c-2b)', $content);
        $this->assertStringContainsString('`SyncRun` / `SyncRunItem` execution evidence', $content);
        $this->assertStringContainsString('`ExternalRecordLink` persistence and runtime', $content);
    }

    #[Test]
    public function gap_026_records_workspace_scoped_rbac_mismatch(): void
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('## GAP-026 — Workspace-scoped RBAC foundation not implemented', $content);
        $this->assertStringContainsString('`User.role`', $content);
        $this->assertStringContainsString('`ConnectorAccountPolicy`', $content);
        $this->assertStringContainsString('`WorkspaceUser` membership is **not** implemented', $content);
        $this->assertStringContainsString("'teams' => false", $content);
        $this->assertStringContainsString('`view_sync_mappings`', $content);
        $this->assertStringContainsString('`manage_sync_mappings`', $content);
        $this->assertStringContainsString('**Cross-reference:** **GAP-004**', $content);
        $this->assertStringContainsString('blocks 4C-1c-2b mapping UI', $content);
    }
}
