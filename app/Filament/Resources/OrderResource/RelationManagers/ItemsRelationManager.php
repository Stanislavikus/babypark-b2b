<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Позиції замовлення';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sku')
                    ->label('Артикул')
                    ->disabled(),
                TextInput::make('name')
                    ->label('Назва')
                    ->disabled(),
                TextInput::make('quantity')
                    ->label('Кількість')
                    ->disabled(),
                TextInput::make('price_with_vat')
                    ->label('Ціна з ПДВ')
                    ->disabled()
                    ->prefix('₴'),
                TextInput::make('manager_price')
                    ->label('Ціна менеджера')
                    ->numeric()
                    ->prefix('₴'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->columns([
                TextColumn::make('sku')
                    ->label('Артикул'),
                TextColumn::make('name')
                    ->label('Назва')
                    ->limit(40),
                TextColumn::make('quantity')
                    ->label('К-сть'),
                TextColumn::make('price_with_vat')
                    ->label('Ціна з ПДВ')
                    ->money('UAH'),
                TextColumn::make('total')
                    ->label('Сума')
                    ->money('UAH'),
                TextColumn::make('manager_price')
                    ->label('Ціна менеджера')
                    ->money('UAH')
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
