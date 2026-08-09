<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\FieldMatrix;
use App\Models\User;
use App\Support\CanonicalRegistry\CanonicalRegistryReader;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class FieldMatrixPageTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->create([
            'name' => 'Platform Admin',
            'email' => 'platform-admin-'.uniqid().'@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function field_matrix_compare_channels_uses_filament_checkbox_list(): void
    {
        app()->setLocale('uk');

        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSuccessful()
            ->assertSee(__('field_matrix.compare_channels'))
            ->assertSee(__('field_matrix.compare_channels_helper'));

        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->instance();

        $checkboxList = $component->getForm('form')->getComponent(
            fn (Component $field): bool => $field instanceof CheckboxList
                && method_exists($field, 'getName')
                && $field->getName() === 'selectedColumnKeys'
        );

        $this->assertNotNull($checkboxList);
        $this->assertFalse($checkboxList->isSearchable());
        $this->assertFalse($checkboxList->isBulkToggleable());
    }

    #[Test]
    public function field_matrix_comparison_checkbox_list_is_not_searchable_for_small_option_set(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->instance();

        $optionCount = count($component->columnOptions());
        $this->assertLessThanOrEqual(8, $optionCount);

        $checkboxList = $component->getForm('form')->getComponent(
            fn (Component $field): bool => $field instanceof CheckboxList
                && method_exists($field, 'getName')
                && $field->getName() === 'selectedColumnKeys'
        );

        $this->assertNotNull($checkboxList);
        $this->assertFalse($checkboxList->isSearchable());
    }

    #[Test]
    public function field_matrix_comparison_checkbox_list_is_rendered_once(): void
    {
        app()->setLocale('uk');

        $blade = File::get(resource_path('views/filament/pages/field-matrix.blade.php'));

        $this->assertEquals(1, substr_count($blade, '{{ $this->form }}'));

        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSee(__('field_matrix.compare_channels_helper'));
    }

    #[Test]
    public function field_matrix_comparison_checkbox_list_does_not_render_select_chips(): void
    {
        $html = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->html();

        $this->assertStringNotContainsString('choices__list', $html);
        $this->assertStringNotContainsString('fi-select-input-value-label', $html);
        $this->assertStringContainsString('fi-fo-checkbox-list', $html);
    }

    #[Test]
    public function field_matrix_comparison_checkbox_list_does_not_enable_bulk_toggle(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->instance();

        $checkboxList = $component->getForm('form')->getComponent(
            fn (Component $field): bool => $field instanceof CheckboxList
                && method_exists($field, 'getName')
                && $field->getName() === 'selectedColumnKeys'
        );

        $this->assertNotNull($checkboxList);
        $this->assertFalse($checkboxList->isBulkToggleable());

        $html = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->html();

        $this->assertStringNotContainsString('selectAll', $html);
    }

    #[Test]
    public function field_matrix_six_selected_values_disable_only_unchecked_options(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class);

        $availableColumns = collect(range(1, 8))
            ->map(fn (int $index): array => [
                'channel' => 'channel_'.$index,
                'channel_schema_version' => 'v'.$index,
            ])
            ->all();

        $sixKeys = collect($availableColumns)
            ->take(6)
            ->map(fn (array $column): string => $column['channel'].'|'.$column['channel_schema_version'])
            ->all();

        $instance = $component->instance();
        $instance->availableColumns = $availableColumns;
        $instance->data['selectedColumnKeys'] = $sixKeys;

        $checkboxList = $instance->getForm('form')->getComponent(
            fn (Component $field): bool => $field instanceof CheckboxList
                && method_exists($field, 'getName')
                && $field->getName() === 'selectedColumnKeys'
        );
        $this->assertNotNull($checkboxList);

        foreach ($sixKeys as $key) {
            $this->assertFalse(
                $checkboxList->isOptionDisabled($key, $instance->columnOptions()[$key]),
                "Selected option {$key} should remain enabled."
            );
        }

        $uncheckedKey = $availableColumns[6]['channel'].'|'.$availableColumns[6]['channel_schema_version'];
        $this->assertTrue(
            $checkboxList->isOptionDisabled($uncheckedKey, $instance->columnOptions()[$uncheckedKey]),
            'Unchecked option should be disabled when six are selected.'
        );
    }

    #[Test]
    public function field_matrix_seventh_comparison_value_rolls_back_and_shows_localized_error(): void
    {
        app()->setLocale('uk');

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

        $checkboxList = $instance->getForm('form')->getComponent(
            fn (Component $field): bool => $field instanceof CheckboxList
                && method_exists($field, 'getName')
                && $field->getName() === 'selectedColumnKeys'
        );
        $this->assertNotNull($checkboxList);

        $set = new Set($checkboxList);
        $handler = (new ReflectionClass(FieldMatrix::class))->getMethod('handleSelectedColumnKeysUpdated');
        $handler->setAccessible(true);
        $handler->invoke($instance, $sevenKeys, $sixKeys, $set);

        $this->assertSame($sixKeys, $instance->data['selectedColumnKeys']);
        $this->assertTrue($instance->getErrorBag()->has('data.selectedColumnKeys'));
        $this->assertSame(
            __('field_matrix.compare_channels_limit_error'),
            $instance->getErrorBag()->first('data.selectedColumnKeys')
        );
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
    public function field_matrix_null_comparison_value_normalizes_to_empty_array(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->set('data.selectedColumnKeys', null)
            ->assertHasNoErrors()
            ->assertSet('data.selectedColumnKeys', []);

        $this->assertSame([], $component->instance()->selectedColumns());
        $this->assertNotEmpty($component->instance()->matrix);
        $this->assertTrue(
            collect($component->instance()->matrix)->every(
                fn (array $row): bool => $row['cells'] === []
            )
        );
    }

    #[Test]
    public function field_matrix_renders_uk_labels_when_locale_is_uk(): void
    {
        app()->setLocale('uk');

        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSee(__('field_matrix.compare_channels'))
            ->assertSee(__('field_matrix.filter_binding'))
            ->assertSee(__('field_matrix.binding_product'))
            ->assertSee(__('field_matrix.binding_variant'))
            ->assertDontSee('Product / Variant / Both')
            ->assertDontSee('Product + Variant');
    }

    #[Test]
    public function field_matrix_renders_ru_labels_when_locale_is_ru(): void
    {
        app()->setLocale('ru');

        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSee(__('field_matrix.compare_channels'))
            ->assertSee(__('field_matrix.filter_binding'))
            ->assertSee(__('field_matrix.binding_product'))
            ->assertDontSee('Product / Variant / Both');
    }

    #[Test]
    public function field_matrix_renders_en_labels_when_locale_is_en(): void
    {
        app()->setLocale('en');

        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSee(__('field_matrix.compare_channels'))
            ->assertSee(__('field_matrix.filter_binding'))
            ->assertSee('Product variant')
            ->assertSee('Product and variant');
    }

    #[Test]
    public function field_matrix_translation_keys_are_complete_for_all_supported_locales(): void
    {
        $ukKeys = array_keys(require lang_path('uk/field_matrix.php'));
        $ruKeys = array_keys(require lang_path('ru/field_matrix.php'));
        $enKeys = array_keys(require lang_path('en/field_matrix.php'));

        sort($ukKeys);
        sort($ruKeys);
        sort($enKeys);

        $this->assertSame($ukKeys, $ruKeys);
        $this->assertSame($ukKeys, $enKeys);
    }

    #[Test]
    public function field_matrix_has_no_hardcoded_product_variant_filter_labels(): void
    {
        $php = File::get(app_path('Filament/Pages/FieldMatrix.php'));
        $blade = File::get(resource_path('views/filament/pages/field-matrix.blade.php'));

        $this->assertStringNotContainsString('Product / Variant / Both', $php);
        $this->assertStringNotContainsString('Product / Variant / Both', $blade);
        $this->assertStringNotContainsString('Product + Variant', $php);
        $this->assertStringNotContainsString('Product + Variant', $blade);
    }

    #[Test]
    public function field_matrix_validation_error_is_localized(): void
    {
        app()->setLocale('ru');

        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class);

        $validKey = $component->instance()->availableColumns[0]['channel']
            .'|'.$component->instance()->availableColumns[0]['channel_schema_version'];

        $component
            ->fillForm(['selectedColumnKeys' => [$validKey, $validKey]])
            ->assertHasErrors(['data.selectedColumnKeys']);

        $this->assertSame(
            __('field_matrix.compare_channels_limit_error'),
            $component->instance()->getErrorBag()->first('data.selectedColumnKeys')
        );
    }

    #[Test]
    public function field_matrix_defaults_to_field_sort_ascending(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class);

        $this->assertSame('asc', $component->instance()->fieldSortDirection());
        $component->assertSeeHtml('aria-sort="ascending"');
    }

    #[Test]
    public function field_matrix_field_header_toggles_to_descending(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->call('toggleFieldSortDirection');

        $this->assertSame('desc', $component->instance()->fieldSortDirection());
        $component->assertSeeHtml('aria-sort="descending"');
        $component->assertSeeHtml('data-testid="field-matrix-sort-desc"');
    }

    #[Test]
    public function field_matrix_field_header_toggles_back_to_ascending(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->call('toggleFieldSortDirection')
            ->call('toggleFieldSortDirection');

        $this->assertSame('asc', $component->instance()->fieldSortDirection());
        $component->assertSeeHtml('aria-sort="ascending"');
    }

    #[Test]
    public function field_matrix_sort_preserves_search_filters_and_selected_channels(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class);

        $selectedKeys = $component->get('data.selectedColumnKeys');
        $component
            ->set('data.search', 'name')
            ->set('data.fieldGroup', 'seo')
            ->set('data.bindingStrategy', 'product')
            ->call('toggleFieldSortDirection');

        $this->assertSame('name', $component->get('data.search'));
        $this->assertSame('seo', $component->get('data.fieldGroup'));
        $this->assertSame('product', $component->get('data.bindingStrategy'));
        $this->assertSame($selectedKeys, $component->get('data.selectedColumnKeys'));
    }

    #[Test]
    public function field_matrix_sort_uses_internal_code_as_deterministic_tie_breaker(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class);

        $fields = $component->instance()->filteredFields();
        $labels = collect($fields)->map(function (array $field): string {
            return ($field['uk_label'] ?? '') !== ''
                ? $field['uk_label']
                : ($field['canonical_english_name'] ?? '');
        })->all();

        $sortedAsc = $labels;
        usort($sortedAsc, function (string $a, string $b) use ($fields): int {
            $comparison = strcasecmp($a, $b);
            if ($comparison !== 0) {
                return $comparison;
            }

            $codeA = collect($fields)->first(fn (array $f): bool => (($f['uk_label'] ?? '') !== '' ? $f['uk_label'] : ($f['canonical_english_name'] ?? '')) === $a)['internal_code'] ?? '';
            $codeB = collect($fields)->first(fn (array $f): bool => (($f['uk_label'] ?? '') !== '' ? $f['uk_label'] : ($f['canonical_english_name'] ?? '')) === $b)['internal_code'] ?? '';

            return strcasecmp($codeA, $codeB);
        });

        $this->assertSame($sortedAsc, $labels);

        $component->call('toggleFieldSortDirection');
        $descFields = $component->instance()->filteredFields();
        $descLabels = collect($descFields)->map(function (array $field): string {
            return ($field['uk_label'] ?? '') !== ''
                ? $field['uk_label']
                : ($field['canonical_english_name'] ?? '');
        })->all();

        $this->assertNotSame($labels, $descLabels);
    }

    #[Test]
    public function field_matrix_sort_header_exposes_aria_sort(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSeeHtml('data-testid="field-matrix-sort-trigger"')
            ->assertSeeHtml('aria-sort="ascending"')
            ->call('toggleFieldSortDirection')
            ->assertSeeHtml('aria-sort="descending"');
    }

    #[Test]
    public function field_matrix_does_not_render_hardcoded_native_multiple_select(): void
    {
        $blade = File::get(resource_path('views/filament/pages/field-matrix.blade.php'));

        $this->assertStringNotContainsString('wire:model.live="selectedColumnKeys"', $blade);
        $this->assertStringNotContainsString('multiple', $blade);
    }

    #[Test]
    public function field_matrix_uses_single_form_state_source(): void
    {
        $reflection = new ReflectionClass(FieldMatrix::class);

        $this->assertFalse($reflection->hasProperty('selectedColumnKeys'));
        $this->assertTrue($reflection->hasProperty('data'));

        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class);

        $keys = collect($component->instance()->availableColumns)
            ->take(2)
            ->map(fn (array $column): string => $column['channel'].'|'.$column['channel_schema_version'])
            ->all();

        $component
            ->fillForm(['selectedColumnKeys' => $keys])
            ->assertSet('data.selectedColumnKeys', $keys);

        $selected = $component->instance()->selectedColumns();
        $this->assertCount(2, $selected);
        $this->assertSame($keys[0], $selected[0]['channel'].'|'.$selected[0]['channel_schema_version']);
    }

    #[Test]
    public function field_matrix_form_hydrates_existing_selected_columns(): void
    {
        $reader = app(CanonicalRegistryReader::class);
        $columns = $reader->channelColumns();
        $expected = [];
        if (isset($columns[0])) {
            $expected[] = $columns[0]['channel'].'|'.$columns[0]['channel_schema_version'];
        }
        if (isset($columns[1])) {
            $expected[] = $columns[1]['channel'].'|'.$columns[1]['channel_schema_version'];
        }

        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSet('data.selectedColumnKeys', $expected)
            ->assertSet('matrix', fn (array $matrix): bool => count($matrix) > 0);
    }

    #[Test]
    public function field_matrix_treats_channel_versions_as_separate_comparison_columns(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class);

        $instance = $component->instance();
        $instance->availableColumns = [
            ['channel' => 'shopify', 'channel_schema_version' => '2024-10'],
            ['channel' => 'shopify', 'channel_schema_version' => 'unversioned'],
        ];
        $instance->data['selectedColumnKeys'] = ['shopify|2024-10', 'shopify|unversioned'];

        $selected = $instance->selectedColumns();
        $this->assertCount(2, $selected);
        $this->assertSame('2024-10', $selected[0]['channel_schema_version']);
        $this->assertSame('unversioned', $selected[1]['channel_schema_version']);
    }

    #[Test]
    public function field_matrix_rejects_seventh_comparison_column_without_silent_truncation(): void
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

        $this->assertTrue($instance->validComparisonKeys($sixKeys));
        $this->assertFalse($instance->validComparisonKeys($sevenKeys));

        $checkboxList = $instance->getForm('form')->getComponent(
            fn (Component $field): bool => $field instanceof CheckboxList
                && method_exists($field, 'getName')
                && $field->getName() === 'selectedColumnKeys'
        );
        $this->assertNotNull($checkboxList);
        $set = new Set($checkboxList);

        $handler = (new ReflectionClass(FieldMatrix::class))->getMethod('handleSelectedColumnKeysUpdated');
        $handler->setAccessible(true);
        $handler->invoke($instance, $sevenKeys, $sixKeys, $set);

        $this->assertSame($sixKeys, $instance->data['selectedColumnKeys']);
        $this->assertTrue($instance->getErrorBag()->has('data.selectedColumnKeys'));
    }

    #[Test]
    public function field_matrix_filters_by_field_group_or_state(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->set('data.fieldGroup', 'seo');

        $matrix = $component->instance()->matrix;
        $this->assertNotEmpty($matrix);
        $this->assertTrue(
            collect($matrix)->every(fn (array $row): bool => str_contains($row['internal_code'], 'meta_')
                || collect($component->instance()->filteredFields())->pluck('internal_code')->contains($row['internal_code']))
        );

        $filteredCodes = collect($component->instance()->filteredFields())->pluck('internal_code')->all();
        $matrixCodes = collect($matrix)->pluck('internal_code')->all();
        $this->assertSame($filteredCodes, $matrixCodes);
        $this->assertTrue(
            collect($component->instance()->filteredFields())->every(
                fn (array $field): bool => $field['field_group_or_state'] === 'seo'
            )
        );
    }

    #[Test]
    public function field_matrix_filters_by_binding_strategy(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->set('data.bindingStrategy', 'product_variant');

        $this->assertTrue(
            collect($component->instance()->filteredFields())->every(
                fn (array $field): bool => $field['binding_strategy'] === 'product_variant'
            )
        );
        $this->assertNotEmpty($component->instance()->matrix);
    }

    #[Test]
    public function field_matrix_filters_by_scope(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->set('data.scope', 'platform_library');

        $this->assertTrue(
            collect($component->instance()->filteredFields())->every(
                fn (array $field): bool => $field['scope'] === 'platform_library'
            )
        );
        $this->assertNotEmpty($component->instance()->matrix);
    }

    #[Test]
    public function field_matrix_searches_uk_english_name_and_internal_code(): void
    {
        $byCode = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->set('data.search', 'internal_product_id');

        $this->assertCount(1, $byCode->instance()->filteredFields());
        $this->assertSame('internal_product_id', $byCode->instance()->filteredFields()[0]['internal_code']);

        $byEnglish = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->set('data.search', 'Product Name');

        $this->assertTrue(
            collect($byEnglish->instance()->filteredFields())->contains(
                fn (array $field): bool => $field['internal_code'] === 'name'
            )
        );

        $byUk = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->set('data.search', 'Назва товару');

        $this->assertTrue(
            collect($byUk->instance()->filteredFields())->contains(
                fn (array $field): bool => $field['internal_code'] === 'name'
            )
        );
    }

    #[Test]
    public function field_matrix_sorts_by_uk_label_with_stable_fallback(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class);

        $fields = $component->instance()->filteredFields();
        $labels = collect($fields)->map(function (array $field): string {
            return ($field['uk_label'] ?? '') !== ''
                ? $field['uk_label']
                : ($field['canonical_english_name'] ?? '');
        })->all();

        $sorted = $labels;
        sort($sorted, SORT_FLAG_CASE | SORT_STRING);

        $this->assertSame($sorted, $labels);
    }

    #[Test]
    public function field_matrix_remains_read_only(): void
    {
        $blade = File::get(resource_path('views/filament/pages/field-matrix.blade.php'));

        $this->assertStringNotContainsString('Approve', $blade);
        $this->assertStringNotContainsString('Reject', $blade);
        $this->assertStringNotContainsString('EditAction', $blade);
        $this->assertStringNotContainsString('wire:submit', $blade);
    }

    #[Test]
    public function field_matrix_single_column_does_not_stretch_to_full_page_width(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class);

        $key = $component->instance()->availableColumns[0]['channel']
            .'|'.$component->instance()->availableColumns[0]['channel_schema_version'];

        $component
            ->fillForm(['selectedColumnKeys' => [$key]])
            ->assertSeeHtml('inline-block')
            ->assertSeeHtml('w-max');
    }

    #[Test]
    public function field_matrix_page_renders_without_error_for_platform_admin(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSuccessful()
            ->assertSet('availableColumns', fn (array $columns): bool => $columns !== [])
            ->assertSet('matrix', fn (array $matrix): bool => $matrix !== []);
    }

    #[Test]
    public function field_matrix_panel_uses_select_channels_heading(): void
    {
        app()->setLocale('uk');

        $blade = File::get(resource_path('views/filament/pages/field-matrix.blade.php'));

        $this->assertStringContainsString("__('field_matrix.select_channels')", $blade);

        preg_match(
            '/data-testid="field-matrix-panel-compare"[\s\S]*?\{\{ \$this->form \}\}/',
            $blade,
            $compareSection
        );

        $this->assertNotEmpty($compareSection);
        $this->assertStringContainsString("__('field_matrix.select_channels')", $compareSection[0]);
        $this->assertStringNotContainsString("__('field_matrix.compare_channels')", $compareSection[0]);

        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSee(__('field_matrix.select_channels'));
    }

    #[Test]
    public function field_matrix_compare_channels_trigger_label_is_unchanged(): void
    {
        app()->setLocale('uk');

        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSee(__('field_matrix.compare_channels'))
            ->assertSeeHtml('data-testid="compare-channels-trigger"');
    }

    #[Test]
    public function field_matrix_translation_keys_match_across_uk_ru_en(): void
    {
        $uk = require lang_path('uk/field_matrix.php');
        $ru = require lang_path('ru/field_matrix.php');
        $en = require lang_path('en/field_matrix.php');

        $this->assertArrayHasKey('select_channels', $uk);
        $this->assertArrayHasKey('select_channels', $ru);
        $this->assertArrayHasKey('select_channels', $en);
        $this->assertSame('Вибрати канали', $uk['select_channels']);
        $this->assertSame('Выбрать каналы', $ru['select_channels']);
        $this->assertSame('Select channels', $en['select_channels']);

        $ukKeys = array_keys($uk);
        $ruKeys = array_keys($ru);
        $enKeys = array_keys($en);

        sort($ukKeys);
        sort($ruKeys);
        sort($enKeys);

        $this->assertSame($ukKeys, $ruKeys);
        $this->assertSame($ukKeys, $enKeys);
    }

    #[Test]
    public function field_matrix_existing_checkbox_limit_and_sort_contracts_remain_unchanged(): void
    {
        $component = Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class);

        $instance = $component->instance();

        $checkboxList = $instance->getForm('form')->getComponent(
            fn (Component $field): bool => $field instanceof CheckboxList
                && method_exists($field, 'getName')
                && $field->getName() === 'selectedColumnKeys'
        );

        $this->assertNotNull($checkboxList);
        $this->assertFalse($checkboxList->isSearchable());
        $this->assertFalse($checkboxList->isBulkToggleable());
        $this->assertSame('asc', $instance->fieldSortDirection());

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

        $instance->availableColumns = $availableColumns;
        $instance->data['selectedColumnKeys'] = $sixKeys;

        $this->assertTrue($instance->validComparisonKeys($sixKeys));
        $this->assertFalse($instance->validComparisonKeys($sevenKeys));
    }
}
