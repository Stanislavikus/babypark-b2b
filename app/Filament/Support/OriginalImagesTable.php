<?php

namespace App\Filament\Support;

use App\Filament\Resources\MediaAssetResource;
use Filament\Actions\Action;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class OriginalImagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn () => OriginalImageAssetPicker::scopedOriginalImages())
            ->columns(MediaAssetCardColumns::make())
            ->contentGrid(fn ($livewire): ?array => ($livewire->getTableFilterState('picker_view')['value'] ?? 'grid') === 'list'
                ? null
                : ['default' => 1, 'lg' => 2])
            ->defaultSort('created_at', 'desc')
            ->filters([
                ...MediaAssetResource::libraryFilters(),
                SelectFilter::make('picker_view')
                    ->label('Відображення')
                    ->options([
                        'list' => 'Список',
                    ])
                    ->placeholder('Сітка')
                    ->indicateUsing(fn (): array => [])
                    ->query(fn (Builder $query): Builder => $query),
            ], layout: FiltersLayout::AfterContentCollapsible)
            ->deferFilters(false)
            ->filtersFormWidth('3xs')
            ->filtersTriggerAction(fn (Action $action): Action => $action
                ->button()
                ->outlined()
                ->color('gray')
                ->label('Фільтри')
                ->tooltip('Фільтри'));
    }
}
