<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\FieldMatrix;
use App\Filament\Pages\Governance;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Set;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class DataListToolbarTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->create([
            'name' => 'Platform Admin',
            'email' => 'toolbar-admin-'.uniqid().'@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function data_list_toolbar_component_resolves_and_renders(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-filament.data-list-toolbar :filters-count="2" :has-filters="true">
                <x-slot name="search">Search slot</x-slot>
                <x-slot name="filters">Filters slot</x-slot>
                <x-slot name="actions">Actions slot</x-slot>
                <x-slot name="activeFilters">Active filters slot</x-slot>
            </x-filament.data-list-toolbar>
        BLADE);

        $this->assertStringContainsString('data-toolbar-region="search"', $html);
        $this->assertStringContainsString('data-toolbar-region="filters"', $html);
        $this->assertStringContainsString('data-toolbar-region="actions"', $html);
        $this->assertStringContainsString('data-toolbar-region="active-filters"', $html);
        $this->assertStringContainsString('Search slot', $html);
    }

    #[Test]
    public function data_list_toolbar_shows_filter_trigger_only_when_filters_exist(): void
    {
        $withFilters = Blade::render(<<<'BLADE'
            <x-filament.data-list-toolbar :has-filters="true">
                <x-slot name="filters">Filters</x-slot>
            </x-filament.data-list-toolbar>
        BLADE);

        $withoutFilters = Blade::render('<x-filament.data-list-toolbar :has-filters="false" />');

        $this->assertStringContainsString('data-testid="data-list-filter-trigger"', $withFilters);
        $this->assertStringNotContainsString('data-testid="data-list-filter-trigger"', $withoutFilters);
    }

    #[Test]
    public function data_list_toolbar_shows_active_filter_count_badge_only_when_count_is_positive(): void
    {
        $withCount = Blade::render(<<<'BLADE'
            <x-filament.data-list-toolbar :filters-count="2" :has-filters="true">
                <x-slot name="filters">Filters</x-slot>
            </x-filament.data-list-toolbar>
        BLADE);

        $withoutCount = Blade::render(<<<'BLADE'
            <x-filament.data-list-toolbar :filters-count="0" :has-filters="true">
                <x-slot name="filters">Filters</x-slot>
            </x-filament.data-list-toolbar>
        BLADE);

        $this->assertStringContainsString('fi-badge', $withCount);
        $this->assertMatchesRegularExpression('/fi-badge[\s\S]*\b2\b/', $withCount);
        $this->assertStringNotContainsString('fi-badge', $withoutCount);
    }

    #[Test]
    public function data_list_toolbar_active_filters_are_individually_removable(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->set('data.fieldGroup', 'seo')
            ->assertSee('Група:')
            ->assertSeeHtml('wire:click="removeFilter(\'fieldGroup\')"')
            ->assertSeeHtml('aria-label="Видалити фільтр Група"')
            ->call('removeFilter', 'fieldGroup')
            ->assertSet('data.fieldGroup', null);
    }

    #[Test]
    public function data_list_toolbar_clear_all_resets_all_row_filters(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->set('data.fieldGroup', 'seo')
            ->set('data.bindingStrategy', 'product')
            ->set('data.scope', 'system')
            ->call('clearAllFilters')
            ->assertSet('data.fieldGroup', null)
            ->assertSet('data.bindingStrategy', null)
            ->assertSet('data.scope', null);
    }

    #[Test]
    public function field_matrix_search_remains_visible(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSeeHtml('data-toolbar-region="search"')
            ->assertSeeHtml('id="field-matrix-search"')
            ->assertSeeHtml('wire:model.live.debounce.300ms="data.search"');
    }

    #[Test]
    public function field_matrix_compare_channels_is_in_actions_region_not_filters_region(): void
    {
        $blade = File::get(resource_path('views/filament/pages/field-matrix.blade.php'));

        $this->assertStringContainsString('data-testid="compare-channels-trigger"', $blade);
        $this->assertStringContainsString('<x-slot name="actions">', $blade);
        $this->assertStringContainsString('{{ $this->form }}', $blade);

        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSeeHtml('data-toolbar-region="actions"')
            ->assertSeeHtml('data-testid="compare-channels-trigger"');
    }

    #[Test]
    public function field_matrix_compare_channels_keeps_searchable_filament_form_select(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSuccessful()
            ->assertSee('Порівняти канали')
            ->assertSee('Можна одночасно порівнювати не більше 6 варіантів каналів.');

        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->instance();

        $select = $component->getForm('form')->getComponent(
            fn (Component $field): bool => $field instanceof Select
                && method_exists($field, 'getName')
                && $field->getName() === 'selectedColumnKeys'
        );

        $this->assertNotNull($select);
        $this->assertTrue($select->isSearchable());
        $this->assertTrue($select->isMultiple());
    }

    #[Test]
    public function field_matrix_seventh_comparison_value_rolls_back_and_shows_error(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class);

        $availableColumns = collect(range(1, 7))
            ->map(fn (int $index): array => [
                'channel' => 'channel_'.$index,
                'channel_schema_version' => 'v'.$index,
            ])
            ->all();

        $sixKeys = collect($availableColumns)
            ->take(6)
            ->map(fn (array $column): string => $column['channel'].'|'.$column['channel_schema_version'])
            ->all();

        $sevenKeys = collect($availableColumns)
            ->map(fn (array $column): string => $column['channel'].'|'.$column['channel_schema_version'])
            ->all();

        $instance = $component->instance();
        $instance->availableColumns = $availableColumns;
        $instance->data['selectedColumnKeys'] = $sixKeys;

        $form = $instance->getForm('form');
        $select = $form->getComponent(
            fn (Component $field): bool => $field instanceof Select
                && method_exists($field, 'getName')
                && $field->getName() === 'selectedColumnKeys'
        );
        $this->assertNotNull($select);

        $set = new Set($select);
        $handler = (new ReflectionClass(FieldMatrix::class))->getMethod('handleSelectedColumnKeysUpdated');
        $handler->setAccessible(true);
        $handler->invoke($instance, $sevenKeys, $sixKeys, $set);

        $this->assertSame($sixKeys, $instance->data['selectedColumnKeys']);
        $this->assertTrue($instance->getErrorBag()->has('data.selectedColumnKeys'));
    }

    #[Test]
    public function field_matrix_duplicate_or_unknown_comparison_key_rolls_back(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class);

        $validKey = $component->instance()->availableColumns[0]['channel']
            .'|'.$component->instance()->availableColumns[0]['channel_schema_version'];

        $component
            ->fillForm(['selectedColumnKeys' => [$validKey]])
            ->fillForm(['selectedColumnKeys' => [$validKey, $validKey]])
            ->assertHasErrors(['data.selectedColumnKeys'])
            ->assertSet('data.selectedColumnKeys', [$validKey]);

        $component
            ->fillForm(['selectedColumnKeys' => ['unknown|missing']])
            ->assertHasErrors(['data.selectedColumnKeys']);

        $component
            ->fillForm(['selectedColumnKeys' => [$validKey]])
            ->assertHasNoErrors()
            ->assertSet('data.selectedColumnKeys', [$validKey]);
    }

    #[Test]
    public function governance_uses_shared_toolbar(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSeeHtml('data-toolbar-region="search"')
            ->assertSeeHtml('wire:model.live.debounce.300ms="search"');
    }

    #[Test]
    public function governance_keeps_dec_gap_as_tabs(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSeeHtml('fi-tabs')
            ->assertSee('DEC')
            ->assertSee('GAP')
            ->call('setActiveTab', 'GAP')
            ->assertSet('activeTab', 'GAP');
    }

    #[Test]
    public function governance_does_not_render_empty_filters_trigger(): void
    {
        $blade = File::get(resource_path('views/filament/pages/governance.blade.php'));

        $this->assertStringContainsString(':has-filters="false"', $blade);
        $this->assertStringNotContainsString('data-testid="data-list-filter-trigger"', $blade);

        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertDontSee('Фільтри');
    }

    #[Test]
    public function data_list_toolbar_has_no_vendor_filament_tables_reference(): void
    {
        $paths = [
            resource_path('views/components/filament/data-list-toolbar.blade.php'),
            resource_path('views/filament/pages/field-matrix.blade.php'),
            resource_path('views/filament/pages/governance.blade.php'),
        ];

        foreach ($paths as $path) {
            $contents = File::get($path);
            $this->assertStringNotContainsString('resources/views/vendor/filament-tables', $contents);
            $this->assertStringNotContainsString('filament-tables::', $contents);
        }
    }
}
