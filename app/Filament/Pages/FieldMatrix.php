<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\CanonicalRegistry\CanonicalRegistryReader;
use App\Support\CanonicalRegistry\FieldMatrixPresenter;
use App\Support\Platform\PlatformAdminAuthorization;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class FieldMatrix extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = 'Модель даних і коннектори';

    protected static ?string $navigationLabel = 'Матриця полів';

    protected static ?string $title = 'Матриця полів';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.field-matrix';

    /** @var list<array{channel: string, channel_schema_version: string}> */
    public array $availableColumns = [];

    /** @var array<int, string> */
    public array $selectedColumnKeys = [];

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

        if ($this->availableColumns !== []) {
            $this->selectedColumnKeys = [0];
            if (isset($this->availableColumns[1])) {
                $this->selectedColumnKeys[] = 1;
            }
        }

        $this->refreshMatrix();
    }

    public function refreshMatrix(): void
    {
        $columns = collect($this->selectedColumnKeys)
            ->map(fn (int $index): ?array => $this->availableColumns[$index] ?? null)
            ->filter()
            ->values()
            ->take(4)
            ->all();

        $this->matrix = app(FieldMatrixPresenter::class)->buildMatrix($columns);
    }

    public function updatedSelectedColumnKeys(): void
    {
        $this->selectedColumnKeys = array_slice(array_values($this->selectedColumnKeys), 0, 4);
        $this->refreshMatrix();
    }

    /**
     * @return array<string, string>
     */
    public function columnOptions(): array
    {
        $options = [];
        foreach ($this->availableColumns as $index => $column) {
            $options[$index] = $column['channel'].' ('.$column['channel_schema_version'].')';
        }

        return $options;
    }

    /**
     * @return list<array{channel: string, channel_schema_version: string}>
     */
    public function selectedColumns(): array
    {
        return collect($this->selectedColumnKeys)
            ->map(fn (int $index): ?array => $this->availableColumns[$index] ?? null)
            ->filter()
            ->values()
            ->take(4)
            ->all();
    }
}
