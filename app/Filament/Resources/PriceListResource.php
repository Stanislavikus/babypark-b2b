<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\EditAction;
use App\Filament\Resources\PriceListResource\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\PriceListResource\Pages\ListPriceLists;
use App\Filament\Resources\PriceListResource\Pages\CreatePriceList;
use App\Filament\Resources\PriceListResource\Pages\EditPriceList;
use App\Enums\PriceListStatus;
use App\Filament\Resources\PriceListResource\Pages;
use App\Filament\Resources\PriceListResource\RelationManagers;
use App\Filament\Resources\PriceListResource\Support\GuardedDeletePriceListAction;
use App\Filament\Resources\PriceListResource\Support\MakeDefaultPriceListAction;
use App\Models\PriceList;
use App\Support\Filament\RevalidatesOnUpdate;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PriceListResource extends Resource
{
    protected static ?string $model = PriceList::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string | \UnitEnum | null $navigationGroup = 'B2B';

    protected static ?string $navigationLabel = 'Прайс-листи';

    protected static ?string $modelLabel = 'прайс-лист';

    protected static ?string $pluralModelLabel = 'Прайс-листи';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основне')->schema([
                    TextInput::make('name')
                        ->label('Назва')
                        ->required()
                        ->maxLength(255),
                    Select::make('currency')
                        ->label('Валюта')
                        ->options([
                            'UAH' => 'UAH',
                        ])
                        ->default(config('pricing.default_currency', 'UAH'))
                        ->disabled()
                        ->dehydrated(),
                    TextInput::make('priority')
                        ->label('Пріоритет')
                        ->numeric()
                        ->integer()
                        ->default(0)
                        ->required(),
                    RevalidatesOnUpdate::apply(
                        Select::make('status')
                            ->label('Статус')
                            ->options(PriceListStatus::options())
                            ->default(PriceListStatus::Active->value)
                            ->required(),
                    ),
                ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('currency')
                    ->label('Валюта'),
                IconColumn::make('is_default')
                    ->label('За замовчуванням')
                    ->boolean()
                    ->trueIcon('heroicon-o-star')
                    ->falseIcon('heroicon-o-minus')
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('Пріоритет')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(
                        fn ($state): string => $state instanceof PriceListStatus
                            ? $state->label()
                            : PriceListStatus::from((string) $state)->label()
                    )
                    ->color(fn ($state): string => ($state instanceof PriceListStatus ? $state : PriceListStatus::from((string) $state)) === PriceListStatus::Active
                        ? 'success'
                        : 'gray'),
                TextColumn::make('items_count')
                    ->label('Кількість позицій')
                    ->counts('items')
                    ->sortable(),
                TextColumn::make('customers_count')
                    ->label('Кількість клієнтів')
                    ->counts('customers')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(PriceListStatus::options()),
                TernaryFilter::make('is_default')
                    ->label('За замовчуванням'),
            ])
            ->recordActions([
                EditAction::make(),
                MakeDefaultPriceListAction::makeTableAction(),
                GuardedDeletePriceListAction::makeTableAction(),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceLists::route('/'),
            'create' => CreatePriceList::route('/create'),
            'edit' => EditPriceList::route('/{record}/edit'),
        ];
    }
}
