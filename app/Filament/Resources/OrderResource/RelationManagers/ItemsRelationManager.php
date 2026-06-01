<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Позиції замовлення';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('sku')
                    ->label('Артикул')
                    ->disabled(),
                Forms\Components\TextInput::make('name')
                    ->label('Назва')
                    ->disabled(),
                Forms\Components\TextInput::make('quantity')
                    ->label('Кількість')
                    ->disabled(),
                Forms\Components\TextInput::make('price_with_vat')
                    ->label('Ціна з ПДВ')
                    ->disabled()
                    ->prefix('₴'),
                Forms\Components\TextInput::make('manager_price')
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
                Tables\Columns\TextColumn::make('sku')
                    ->label('Артикул'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Назва')
                    ->limit(40),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('К-сть'),
                Tables\Columns\TextColumn::make('price_with_vat')
                    ->label('Ціна з ПДВ')
                    ->money('UAH'),
                Tables\Columns\TextColumn::make('total')
                    ->label('Сума')
                    ->money('UAH'),
                Tables\Columns\TextColumn::make('manager_price')
                    ->label('Ціна менеджера')
                    ->money('UAH')
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
