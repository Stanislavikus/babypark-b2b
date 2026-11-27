<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GAP-024 PR4: Published Filament table search-field contract for F5 + Livewire 4.
 */
class FilamentTableSearchFieldLivewire4Test extends TestCase
{
    use RefreshDatabase;

    public function test_published_search_field_uses_livewire_4_blur_binding_and_enter_refresh(): void
    {
        $path = resource_path('views/vendor/filament-tables/components/search-field.blade.php');

        $this->assertFileExists($path);

        $contents = file_get_contents($path);

        $this->assertStringContainsString(
            "'wire:model.live.blur'",
            $contents,
            'Search field must use Livewire 4 wire:model.live.blur when onBlur is true.',
        );
        $this->assertStringContainsString(
            'wire:model.live.debounce.{$debounce}',
            $contents,
            'Search field must keep live debounce binding for non-blur mode.',
        );
        $this->assertStringContainsString(
            '$wire.$refresh()',
            $contents,
            'Enter key must trigger $wire.$refresh() on the search field.',
        );
        $this->assertStringContainsString("'autocomplete' => 'off'", $contents);
        $this->assertStringContainsString("'maxlength' => 1000", $contents);
        $this->assertStringNotContainsString('MagnifyingGlass', $contents);
        $this->assertDoesNotMatchRegularExpression(
            '/<x-filament::input\.wrapper[^>]*\binline-prefix\b/',
            $contents,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/:prefix-icon=/',
            $contents,
        );
    }

    public function test_table_index_override_keeps_search_as_direct_flex_toolbar_child(): void
    {
        $path = resource_path('views/vendor/filament-tables/index.blade.php');

        $this->assertFileExists($path);

        $contents = file_get_contents($path);

        $this->assertStringContainsString('fi-ta-header-toolbar-search min-w-0 flex-1', $contents);
        $this->assertStringContainsString('<x-filament-tables::search-field', $contents);
    }
}
