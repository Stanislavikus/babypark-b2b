<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\ViewAction;
use App\Filament\Resources\StockResource\Pages\ListStocks;
use App\Filament\Resources\StockResource\Pages\ViewStock;
use App\Filament\Resources\StockResource\Pages;
use App\Models\Category;
use App\Models\Stock;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class StockResource extends Resource
{
    protected static ?string $model = Stock::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-archive-box';

    protected static string | \UnitEnum | null $navigationGroup = 'Каталог';

    protected static ?string $modelLabel = 'залишок';

    protected static ?string $pluralModelLabel = 'Залишки';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Товар')->schema([
                    TextEntry::make('variant.product.category.name')
                        ->label('Категорія')
                        ->placeholder('—'),
                    TextEntry::make('variant.product.name')
                        ->label('Назва товару')
                        ->placeholder('—'),
                    TextEntry::make('variant.sku')
                        ->label('Артикул')
                        ->placeholder('—'),
                ])->columns(3),

                Section::make('Залишки')->schema([
                    TextEntry::make('inventoryLocation.name')
                        ->label('Локація'),
                    TextEntry::make('quantity')
                        ->label('Кількість'),
                    TextEntry::make('variant.available_quantity_cache')
                        ->label('Доступно (варіант)')
                        ->placeholder('—'),
                    TextEntry::make('expected_date')
                        ->label('Очікується')
                        ->date('d.m.Y')
                        ->placeholder('—'),
                    TextEntry::make('expected_quantity')
                        ->label('Очікувана кількість')
                        ->placeholder('—'),
                    TextEntry::make('updated_at')
                        ->label('Оновлено')
                        ->dateTime('d.m.Y H:i'),
                ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('variant.product.category.name')
                    ->label('Категорія')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('variant.product.name')
                    ->label('Товар')
                    ->limit(40)
                    ->searchable(),
                TextColumn::make('variant.sku')
                    ->label('Артикул')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('inventoryLocation.name')
                    ->label('Локація')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->join(
                                'inventory_locations',
                                'stocks.inventory_location_id',
                                '=',
                                'inventory_locations.id'
                            )
                            ->orderBy('inventory_locations.name', $direction)
                            ->select('stocks.*');
                    }),
                TextColumn::make('quantity')
                    ->label('Кількість')
                    ->sortable(),
                TextColumn::make('variant.available_quantity_cache')
                    ->label('Доступно (варіант)')
                    ->sortable(),
                TextColumn::make('expected_date')
                    ->label('Очікується')
                    ->date('d.m.Y')
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->label('Оновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('inventoryLocation.name', 'asc')
            ->filters([
                SelectFilter::make('category')
                    ->label('Категорія')
                    ->options(fn () => Category::orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn ($query, array $data) => $data['value']
                        ? $query->whereHas('variant.product', fn ($q) => $q->where('category_id', $data['value']))
                        : $query
                    ),
                SelectFilter::make('inventory_location_id')
                    ->label('Локація')
                    ->relationship('inventoryLocation', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStocks::route('/'),
            'view' => ViewStock::route('/{record}'),
        ];
    }




    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }
}
