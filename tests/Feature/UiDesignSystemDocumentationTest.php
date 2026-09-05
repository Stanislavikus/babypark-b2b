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

    #[Test]
    public function ui_design_system_documents_selection_semantics(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString(
            'A checkbox is not selected merely because a filter has more than two',
            $content
        );
        $this->assertStringContainsString(
            'When exactly one value may be active, use Select, Radio or an approved',
            $content
        );
    }

    #[Test]
    public function ui_design_system_documents_filter_and_selection_badge_semantics(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('### Filter and Selection Count Semantics', $content);
        $this->assertStringContainsString('counts active filter dimensions', $content);
        $this->assertStringContainsString('Do not combine row-filter count and comparison/column count into one', $content);
        $this->assertStringContainsString('Порівняти канали [4]', $content);
    }

    #[Test]
    public function ui_design_system_documents_slide_over_default(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('### Filter Panel Presentation', $content);
        $this->assertStringContainsString('right-side slide-over', $content);
        $this->assertStringContainsString('FiltersLayout::Modal', $content);
        $this->assertStringContainsString('slideOver()', $content);
        $this->assertStringContainsString('data-list-toolbar.blade.php', $content);
    }

    #[Test]
    public function ui_design_system_preserves_native_filament_tables_for_eloquent_lists(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('Eloquent-backed lists continue to use native `Filament\\Tables\\Table`', $content);
        $this->assertStringContainsString(
            'Do not replace a native Eloquent table with the custom non-Eloquent',
            $content
        );
    }

    #[Test]
    public function ui_design_system_documents_migration_sequencing(): void
    {
        $content = File::get(base_path('docs/06-UI_DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('### Migration Sequencing for Existing Pages', $content);
        $this->assertStringContainsString('existing pages are not mass-rewritten in unrelated PRs', $content);
        $this->assertStringContainsString('preserve native Filament Tables for Eloquent-backed data', $content);
        $this->assertStringContainsString('A divergent existing page is not a precedent for new work', $content);
    }
}
