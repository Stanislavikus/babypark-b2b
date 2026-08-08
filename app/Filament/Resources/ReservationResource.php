<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DateTimePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\Action;
use App\Filament\Resources\ReservationResource\Pages\ListReservations;
use App\Filament\Resources\ReservationResource\Pages\ViewReservation;
use App\Enums\ReservationStatus;
use App\Filament\Resources\ReservationResource\Pages;
use App\Models\Reservation;
use App\Services\Availability\ReservationConfirmer;
use App\Services\Availability\ReservationReleaser;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class ReservationResource extends Resource
{
    protected static ?string $model = Reservation::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bookmark';

    protected static string | \UnitEnum | null $navigationGroup = 'B2B';

    protected static ?string $modelLabel = 'резерв';

    protected static ?string $pluralModelLabel = 'Резерви';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->label('Клієнт')
                    ->relationship('customer', 'name')
                    ->disabled(),
                Select::make('variant_id')
                    ->label('Варіант')
                    ->relationship('variant', 'sku')
                    ->disabled(),
                TextInput::make('quantity')
                    ->label('Кількість')
                    ->disabled(),
                Select::make('status')
                    ->label('Статус')
                    ->options(ReservationStatus::options())
                    ->disabled(),
                DateTimePicker::make('expires_at')
                    ->label('Діє до')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Клієнт')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('variant.sku')
                    ->label('Артикул')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->label('Кількість')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ReservationStatus ? $state->label() : (string) $state),
                TextColumn::make('expires_at')
                    ->label('Діє до')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(ReservationStatus::options()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('confirm')
                    ->label('Підтвердити')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Reservation $record): bool => $record->status === ReservationStatus::Pending)
                    ->action(fn (Reservation $record) => app(ReservationConfirmer::class)->confirm($record)),
                Action::make('cancel')
                    ->label('Скасувати')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Reservation $record): bool => $record->status === ReservationStatus::Pending)
                    ->action(fn (Reservation $record) => app(ReservationReleaser::class)->release($record, 'cancelled')),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReservations::route('/'),
            'view' => ViewReservation::route('/{record}'),
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
