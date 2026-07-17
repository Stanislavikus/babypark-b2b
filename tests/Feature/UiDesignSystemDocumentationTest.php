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
}
