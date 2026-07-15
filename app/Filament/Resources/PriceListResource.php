<?php

namespace App\Filament\Resources;

use App\Enums\PriceListStatus;
use App\Filament\Resources\PriceListResource\Pages;
use App\Filament\Resources\PriceListResource\RelationManagers;
use App\Filament\Resources\PriceListResource\Support\GuardedDeletePriceListAction;
use App\Filament\Resources\PriceListResource\Support\MakeDefaultPriceListAction;
use App\Models\PriceList;
use App\Support\Filament\RevalidatesOnUpdate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PriceListResource extends Resource
{
    protected static ?string $model = PriceList::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'B2B';

    protected static ?string $navigationLabel = 'Прайс-листи';

    protected static ?string $modelLabel = 'прайс-лист';

    protected static ?string $pluralModelLabel = 'Прайс-листи';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основне')->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Назва')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('currency')
                        ->label('Валюта')
                        ->options([
                            'UAH' => 'UAH',
                        ])
                        ->default(config('pricing.default_currency', 'UAH'))
                        ->disabled()
                        ->dehydrated(),
                    Forms\Components\TextInput::make('priority')
                        ->label('Пріоритет')
                        ->numeric()
                        ->integer()
                        ->default(0)
                        ->required(),
                    RevalidatesOnUpdate::apply(
                        Forms\Components\Select::make('status')
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
                Tables\Columns\TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency')
                    ->label('Валюта'),
                Tables\Columns\IconColumn::make('is_default')
                    ->label('За замовчуванням')
                    ->boolean()
                    ->trueIcon('heroicon-o-star')
                    ->falseIcon('heroicon-o-minus')
                    ->sortable(),
                Tables\Columns\TextColumn::make('priority')
                    ->label('Пріоритет')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
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
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Кількість позицій')
                    ->counts('items')
                    ->sortable(),
                Tables\Columns\TextColumn::make('customers_count')
                    ->label('Кількість клієнтів')
                    ->counts('customers')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(PriceListStatus::options()),
                Tables\Filters\TernaryFilter::make('is_default')
                    ->label('За замовчуванням'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                MakeDefaultPriceListAction::makeTableAction(),
                GuardedDeletePriceListAction::makeTableAction(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPriceLists::route('/'),
            'create' => Pages\CreatePriceList::route('/create'),
            'edit' => Pages\EditPriceList::route('/{record}/edit'),
        ];
    }
}
