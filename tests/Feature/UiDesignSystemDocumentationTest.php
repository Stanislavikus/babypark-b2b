<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UiDesignSystemDocumentationTest extends TestCase
{
    #[Test]
    public function ui_design_system_documents_six_comparison_columns(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('up to 6', $content);
        $this->assertStringContainsString('channel/version comparison columns', $content);
        $this->assertStringNotContainsString('up to 4', $content);
    }

    #[Test]
    public function ui_design_system_documents_toolbar_action_labels(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('### Toolbar Action Labels', $content);
        $this->assertStringContainsString('icon plus a persistent visible text label', $content);
        $this->assertStringContainsString('Compare channels', $content);
        $this->assertStringContainsString('Do not rely on hover-only tooltips', $content);
    }

    #[Test]
    public function ui_design_system_documents_selection_control_guidance(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('### Choosing a Selection Control', $content);
        $this->assertStringContainsString('CheckboxList', $content);
        $this->assertStringContainsString('searchable multi-select', $content);
        $this->assertStringContainsString('Do not use checkboxes for a single-choice filter', $content);
    }
}
