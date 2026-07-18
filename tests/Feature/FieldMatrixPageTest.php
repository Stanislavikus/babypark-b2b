<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\FieldMatrix;
use App\Models\User;
use App\Support\CanonicalRegistry\CanonicalRegistryReader;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Set;
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
    public function field_matrix_uses_filament_searchable_multi_select(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSuccessful()
            ->assertSee('Порівняти канали')
            ->assertSee('Можна одночасно порівнювати не більше 6 варіантів каналів.');
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

        $form = $instance->getForm('form');
        $select = $form->getComponent(
            fn (Component $component): bool => $component instanceof Select
                && method_exists($component, 'getName')
                && $component->getName() === 'selectedColumnKeys'
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
    public function field_matrix_rejects_unknown_or_duplicate_comparison_keys(): void
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
    public function field_matrix_allows_clearing_all_comparison_columns(): void
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
}
