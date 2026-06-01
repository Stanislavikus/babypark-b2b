<?php

namespace App\Filament\Resources;

use App\Enums\ReservationStatus;
use App\Filament\Resources\ReservationResource\Pages;
use App\Models\Reservation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ReservationResource extends Resource
{
    protected static ?string $model = Reservation::class;

    protected static ?string $navigationIcon = 'heroicon-o-bookmark';

    protected static ?string $navigationGroup = 'B2B';

    protected static ?string $modelLabel = 'резерв';

    protected static ?string $pluralModelLabel = 'Резерви';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('contractor_id')
                    ->label('Контрагент')
                    ->relationship('contractor', 'name')
                    ->disabled(),
                Forms\Components\Select::make('variant_id')
                    ->label('Варіант')
                    ->relationship('variant', 'sku')
                    ->disabled(),
                Forms\Components\TextInput::make('quantity')
                    ->label('Кількість')
                    ->disabled(),
                Forms\Components\Select::make('status')
                    ->label('Статус')
                    ->options(ReservationStatus::options())
                    ->disabled(),
                Forms\Components\DateTimePicker::make('expires_at')
                    ->label('Діє до')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('contractor.name')
                    ->label('Контрагент')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('variant.sku')
                    ->label('Артикул')
                    ->searchable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Кількість')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ReservationStatus ? $state->label() : (string) $state),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Діє до')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(ReservationStatus::options()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('confirm')
                    ->label('Підтвердити')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Reservation $record): bool => $record->status === ReservationStatus::Active)
                    ->action(fn (Reservation $record) => $record->update(['status' => ReservationStatus::Confirmed])),
                Tables\Actions\Action::make('cancel')
                    ->label('Скасувати')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Reservation $record): bool => in_array($record->status, [ReservationStatus::Active, ReservationStatus::Confirmed], true))
                    ->action(fn (Reservation $record) => $record->update(['status' => ReservationStatus::Cancelled])),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReservations::route('/'),
            'view' => Pages\ViewReservation::route('/{record}'),
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
}
