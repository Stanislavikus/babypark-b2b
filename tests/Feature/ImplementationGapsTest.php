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
}
