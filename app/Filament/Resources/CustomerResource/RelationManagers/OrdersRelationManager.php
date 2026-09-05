<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
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
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('onec_number')
                    ->label('№ 1С')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof OrderStatus ? $state->label() : (string) $state),
                TextColumn::make('total_with_vat')
                    ->label('Сума з ПДВ')
                    ->money('UAH')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Створено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => OrderResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
