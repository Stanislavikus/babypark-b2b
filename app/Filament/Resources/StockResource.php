<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockResource\Pages;
use App\Models\Category;
use App\Models\Stock;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StockResource extends Resource
{
    protected static ?string $model = Stock::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Каталог';

    protected static ?string $modelLabel = 'залишок';

    protected static ?string $pluralModelLabel = 'Залишки';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Товар')->schema([
                    Infolists\Components\TextEntry::make('variant.product.category.name')
                        ->label('Категорія')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('variant.product.name')
                        ->label('Назва товару')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('variant.sku')
                        ->label('Артикул')
                        ->placeholder('—'),
                ])->columns(3),

                Infolists\Components\Section::make('Залишки')->schema([
                    Infolists\Components\TextEntry::make('warehouse_name')
                        ->label('Склад'),
                    Infolists\Components\TextEntry::make('quantity')
                        ->label('Кількість'),
                    Infolists\Components\TextEntry::make('reserved')
                        ->label('Резерв'),
                    Infolists\Components\TextEntry::make('expected_date')
                        ->label('Очікується')
                        ->date('d.m.Y')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('expected_quantity')
                        ->label('Очікувана кількість')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('updated_at')
                        ->label('Оновлено')
                        ->dateTime('d.m.Y H:i'),
                ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('variant.product.category.name')
                    ->label('Категорія')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('variant.product.name')
                    ->label('Товар')
                    ->limit(40)
                    ->searchable(),
                Tables\Columns\TextColumn::make('variant.sku')
                    ->label('Артикул')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('warehouse_name')
                    ->label('Склад')
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Кількість')
                    ->sortable(),
                Tables\Columns\TextColumn::make('reserved')
                    ->label('Резерв')
                    ->sortable(),
                Tables\Columns\TextColumn::make('expected_date')
                    ->label('Очікується')
                    ->date('d.m.Y')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Оновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('warehouse_name')
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Категорія')
                    ->options(fn () => Category::orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn ($query, array $data) => $data['value']
                        ? $query->whereHas('variant.product', fn ($q) => $q->where('category_id', $data['value']))
                        : $query
                    ),
                Tables\Filters\SelectFilter::make('warehouse_name')
                    ->label('Склад')
                    ->options(fn () => Stock::query()->distinct()->pluck('warehouse_name', 'warehouse_name')->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStocks::route('/'),
            'view' => Pages\ViewStock::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
