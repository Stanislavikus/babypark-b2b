<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\CanonicalRegistry\CanonicalRegistryReader;
use App\Support\CanonicalRegistry\FieldMatrixPresenter;
use App\Support\Platform\PlatformAdminAuthorization;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

class FieldMatrix extends Page implements HasForms
{
    use InteractsWithForms;

    private const ALLOWED_FILTER_KEYS = ['fieldGroup', 'bindingStrategy', 'scope'];

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = null;

    protected static ?string $title = null;

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.field-matrix';

    /** @var list<array{channel: string, channel_schema_version: string}> */
    public array $availableColumns = [];

    public ?array $data = [];

    /** @var list<array<string, mixed>> */
    public array $matrix = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && PlatformAdminAuthorization::canManage($user);
    }

    public static function getNavigationLabel(): string
    {
        return __('field_matrix.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('field_matrix.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('field_matrix.navigation_group');
    }

    public function mount(): void
    {
        $reader = app(CanonicalRegistryReader::class);
        $this->availableColumns = $reader->channelColumns();

        $defaultKeys = [];
        if ($this->availableColumns !== []) {
            $defaultKeys[] = $this->columnKeyFor($this->availableColumns[0]);
            if (isset($this->availableColumns[1])) {
                $defaultKeys[] = $this->columnKeyFor($this->availableColumns[1]);
            }
        }

        $this->form->fill([
            'selectedColumnKeys' => $defaultKeys,
            'fieldGroup' => null,
            'bindingStrategy' => null,
            'scope' => null,
            'search' => null,
            'fieldSortDirection' => 'asc',
        ]);

        $this->refreshMatrix();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                CheckboxList::make('selectedColumnKeys')
                    ->label(__('field_matrix.compare_channels'))
                    ->helperText(__('field_matrix.compare_channels_helper'))
                    ->options(fn (): array => $this->columnOptions())
                    ->disableOptionWhen(fn (string $value): bool => $this->shouldDisableComparisonOption($value))
                    ->live()
                    ->afterStateUpdated(function (mixed $state, mixed $old, Set $set): void {
                        $this->handleSelectedColumnKeysUpdated($state, $old, $set);
                    }),
            ])
            ->statePath('data');
    }

    public function refreshMatrix(): void
    {
        $this->matrix = app(FieldMatrixPresenter::class)->buildMatrix(
            $this->selectedColumns(),
            $this->filteredFields(),
        );
    }

    public function toggleFieldSortDirection(): void
    {
        $current = $this->data['fieldSortDirection'] ?? 'asc';
        $this->data['fieldSortDirection'] = $current === 'asc' ? 'desc' : 'asc';
        $this->refreshMatrix();
    }

    public function fieldSortDirection(): string
    {
        return ($this->data['fieldSortDirection'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    }

    public function fieldSortAriaValue(): string
    {
        return $this->fieldSortDirection() === 'asc' ? 'ascending' : 'descending';
    }

    public function activeFiltersCount(): int
    {
        $count = 0;

        foreach (self::ALLOWED_FILTER_KEYS as $key) {
            if (filled($this->data[$key] ?? null)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<array{key: string, label: string, value: string}>
     */
    public function activeFilterIndicators(): array
    {
        $definitions = [
            'fieldGroup' => [
                'label' => __('field_matrix.filter_group'),
                'options' => $this->fieldGroupOptions(),
            ],
            'bindingStrategy' => [
                'label' => __('field_matrix.filter_binding'),
                'options' => $this->bindingStrategyOptions(),
            ],
            'scope' => [
                'label' => __('field_matrix.filter_scope'),
                'options' => $this->scopeOptions(),
            ],
        ];

        $indicators = [];

        foreach ($definitions as $key => $definition) {
            $value = $this->data[$key] ?? null;

            if (! filled($value)) {
                continue;
            }

            $indicators[] = [
                'key' => $key,
                'label' => $definition['label'],
                'value' => $definition['options'][$value] ?? (string) $value,
            ];
        }

        return $indicators;
    }

    public function removeFilter(string $key): void
    {
        if (! in_array($key, self::ALLOWED_FILTER_KEYS, true)) {
            return;
        }

        $this->data[$key] = null;
        $this->refreshMatrix();
    }

    public function clearAllFilters(): void
    {
        foreach (self::ALLOWED_FILTER_KEYS as $key) {
            $this->data[$key] = null;
        }

        $this->refreshMatrix();
    }

    public function selectedComparisonColumnCount(): int
    {
        $keys = $this->data['selectedColumnKeys'] ?? [];

        return is_array($keys) ? count($keys) : 0;
    }

    public function updatedDataSelectedColumnKeys(mixed $value): void
    {
        if ($value === null) {
            $this->data['selectedColumnKeys'] = [];
            $this->resetErrorBag('data.selectedColumnKeys');
            $this->refreshMatrix();
        }
    }

    public function updatedDataSearch(): void
    {
        $this->refreshMatrix();
    }

    public function updatedDataFieldGroup(): void
    {
        $this->refreshMatrix();
    }

    public function updatedDataBindingStrategy(): void
    {
        $this->refreshMatrix();
    }

    public function updatedDataScope(): void
    {
        $this->refreshMatrix();
    }

    /**
     * @return list<array{channel: string, channel_schema_version: string}>
     */
    public function selectedColumns(): array
    {
        $keys = $this->data['selectedColumnKeys'] ?? [];
        if (! is_array($keys)) {
            return [];
        }

        return collect($keys)
            ->map(fn (string $key): ?array => $this->columnFromKey($key))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function columnOptions(): array
    {
        $options = [];
        foreach ($this->availableColumns as $column) {
            $key = $this->columnKeyFor($column);
            $options[$key] = $column['channel'].' ('.$column['channel_schema_version'].')';
        }

        return $options;
    }

    public function validComparisonKeys(mixed $state): bool
    {
        $normalizedState = $state === null ? [] : $state;

        if (! is_array($normalizedState)) {
            return false;
        }

        $normalizedState = array_values($normalizedState);

        if (count($normalizedState) > 6) {
            return false;
        }

        if (count($normalizedState) !== count(array_unique($normalizedState))) {
            return false;
        }

        $availableKeys = array_keys($this->columnOptions());
        foreach ($normalizedState as $key) {
            if (! is_string($key) || ! in_array($key, $availableKeys, true)) {
                return false;
            }
        }

        return true;
    }

    public function shouldDisableComparisonOption(string $value): bool
    {
        $selected = $this->data['selectedColumnKeys'] ?? [];

        if (! is_array($selected) || count($selected) < 6) {
            return false;
        }

        return ! in_array($value, $selected, true);
    }

    /**
     * @return list<array<string, string>>
     */
    public function filteredFields(): array
    {
        $reader = app(CanonicalRegistryReader::class);
        $fields = $reader->fields();

        $fieldGroup = $this->data['fieldGroup'] ?? null;
        $bindingStrategy = $this->data['bindingStrategy'] ?? null;
        $scope = $this->data['scope'] ?? null;
        $search = mb_strtolower(trim((string) ($this->data['search'] ?? '')));
        $sortDirection = $this->fieldSortDirection();

        $filtered = collect($fields);

        if (filled($fieldGroup)) {
            $filtered = $filtered->filter(
                fn (array $field): bool => ($field['field_group_or_state'] ?? '') === $fieldGroup
            );
        }

        if (filled($bindingStrategy)) {
            $filtered = $filtered->filter(
                fn (array $field): bool => ($field['binding_strategy'] ?? '') === $bindingStrategy
            );
        }

        if (filled($scope)) {
            $filtered = $filtered->filter(
                fn (array $field): bool => ($field['scope'] ?? '') === $scope
            );
        }

        if ($search !== '') {
            $filtered = $filtered->filter(function (array $field) use ($search): bool {
                return str_contains(mb_strtolower($field['uk_label'] ?? ''), $search)
                    || str_contains(mb_strtolower($field['canonical_english_name'] ?? ''), $search)
                    || str_contains(mb_strtolower($field['internal_code'] ?? ''), $search);
            });
        }

        return $filtered
            ->sort(function (array $a, array $b) use ($sortDirection): int {
                $labelA = ($a['uk_label'] ?? '') !== '' ? $a['uk_label'] : ($a['canonical_english_name'] ?? '');
                $labelB = ($b['uk_label'] ?? '') !== '' ? $b['uk_label'] : ($b['canonical_english_name'] ?? '');

                $comparison = strcasecmp($labelA, $labelB);
                if ($comparison !== 0) {
                    return $sortDirection === 'desc' ? -$comparison : $comparison;
                }

                return strcasecmp($a['internal_code'] ?? '', $b['internal_code'] ?? '');
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function fieldGroupOptions(): array
    {
        $reader = app(CanonicalRegistryReader::class);
        $groups = collect($reader->fields())
            ->pluck('field_group_or_state')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return $groups->mapWithKeys(fn (string $group): array => [
            $group => (string) config('attribute_dictionary.groups.'.$group, $group),
        ])->all();
    }

    /**
     * @return array<string, string>
     */
    public function bindingStrategyOptions(): array
    {
        return [
            'product' => __('field_matrix.binding_product'),
            'product_variant' => __('field_matrix.binding_variant'),
            'product_and_variant_two_bindings' => __('field_matrix.binding_product_and_variant'),
            'not_applicable' => __('field_matrix.not_applicable'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function scopeOptions(): array
    {
        return [
            'system' => __('field_matrix.scope_system'),
            'platform_library' => __('field_matrix.scope_platform_library'),
            'not_applicable' => __('field_matrix.not_applicable'),
        ];
    }

    /**
     * @param  array{channel: string, channel_schema_version: string}  $column
     */
    private function columnKeyFor(array $column): string
    {
        return $column['channel'].'|'.$column['channel_schema_version'];
    }

    /**
     * @return array{channel: string, channel_schema_version: string}|null
     */
    private function columnFromKey(string $key): ?array
    {
        foreach ($this->availableColumns as $column) {
            if ($this->columnKeyFor($column) === $key) {
                return $column;
            }
        }

        return null;
    }

    private function handleSelectedColumnKeysUpdated(mixed $state, mixed $old, Set $set): void
    {
        $errorKey = 'data.selectedColumnKeys';

        if ($state === null) {
            $set('selectedColumnKeys', []);
            $this->resetErrorBag($errorKey);
            $this->refreshMatrix();

            return;
        }

        if (! $this->validComparisonKeys($state)) {
            $set('selectedColumnKeys', is_array($old) ? $old : []);
            $this->addError(
                $errorKey,
                __('field_matrix.compare_channels_limit_error'),
            );

            return;
        }

        $this->resetErrorBag($errorKey);
        $this->refreshMatrix();
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
