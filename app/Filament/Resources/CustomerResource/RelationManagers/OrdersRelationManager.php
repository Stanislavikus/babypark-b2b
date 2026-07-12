<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Останні замовлення';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('onec_number')
                    ->label('№ 1С')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof OrderStatus ? $state->label() : (string) $state),
                Tables\Columns\TextColumn::make('total_with_vat')
                    ->label('Сума з ПДВ')
                    ->money('UAH')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Створено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn ($record) => OrderResource::getUrl('view', ['record' => $record])),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
