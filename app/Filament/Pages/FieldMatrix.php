<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\CanonicalRegistry\CanonicalRegistryReader;
use App\Support\CanonicalRegistry\FieldMatrixPresenter;
use App\Support\Platform\PlatformAdminAuthorization;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class FieldMatrix extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = 'Модель даних і коннектори';

    protected static ?string $navigationLabel = 'Матриця полів';

    protected static ?string $title = 'Матриця полів';

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
        ]);

        $this->refreshMatrix();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make()->schema([
                    Select::make('selectedColumnKeys')
                        ->label('Порівняти канали')
                        ->multiple()
                        ->searchable()
                        ->maxItems(6)
                        ->helperText('Можна одночасно порівнювати не більше 6 варіантів каналів.')
                        ->options(fn (): array => $this->columnOptions())
                        ->live()
                        ->afterStateUpdated(function (mixed $state, mixed $old, Set $set): void {
                            $this->handleSelectedColumnKeysUpdated($state, $old, $set);
                        })
                        ->columnSpanFull(),
                    Select::make('fieldGroup')
                        ->label('Група')
                        ->options(fn (): array => $this->fieldGroupOptions())
                        ->placeholder('Усі групи')
                        ->live()
                        ->afterStateUpdated(fn (): mixed => $this->refreshMatrix()),
                    Select::make('bindingStrategy')
                        ->label('Product / Variant / Both')
                        ->options($this->bindingStrategyOptions())
                        ->placeholder('Усі рівні')
                        ->live()
                        ->afterStateUpdated(fn (): mixed => $this->refreshMatrix()),
                    Select::make('scope')
                        ->label('Походження поля')
                        ->options($this->scopeOptions())
                        ->placeholder('Усі джерела')
                        ->live()
                        ->afterStateUpdated(fn (): mixed => $this->refreshMatrix()),
                    TextInput::make('search')
                        ->label('Пошук')
                        ->placeholder('Назва, код...')
                        ->live(debounce: 300)
                        ->afterStateUpdated(fn (): mixed => $this->refreshMatrix())
                        ->columnSpanFull(),
                ])->columns(2),
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

    public function updatedDataSelectedColumnKeys(mixed $value): void
    {
        if ($value === null) {
            $this->data['selectedColumnKeys'] = [];
            $this->resetErrorBag('data.selectedColumnKeys');
            $this->refreshMatrix();
        }
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
            ->sort(function (array $a, array $b): int {
                $labelA = ($a['uk_label'] ?? '') !== '' ? $a['uk_label'] : ($a['canonical_english_name'] ?? '');
                $labelB = ($b['uk_label'] ?? '') !== '' ? $b['uk_label'] : ($b['canonical_english_name'] ?? '');

                $comparison = strcasecmp($labelA, $labelB);
                if ($comparison !== 0) {
                    return $comparison;
                }

                return strcasecmp($a['internal_code'] ?? '', $b['internal_code'] ?? '');
            })
            ->values()
            ->all();
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
                'Можна обрати не більше 6 доступних варіантів каналів без повторів.',
            );

            return;
        }

        $this->resetErrorBag($errorKey);
        $this->refreshMatrix();
    }

    /**
     * @return array<string, string>
     */
    private function fieldGroupOptions(): array
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
    private function bindingStrategyOptions(): array
    {
        return [
            'product' => 'Product',
            'product_variant' => 'Variant',
            'product_and_variant_two_bindings' => 'Product + Variant',
            'not_applicable' => 'Не застосовується',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function scopeOptions(): array
    {
        return [
            'system' => 'Системне',
            'platform_library' => 'Бібліотека',
            'not_applicable' => 'Не застосовується',
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
