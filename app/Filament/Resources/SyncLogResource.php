<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\ViewAction;
use App\Filament\Resources\SyncLogResource\Pages\ListSyncLogs;
use App\Filament\Resources\SyncLogResource\Pages\ViewSyncLog;
use App\Enums\SyncLogStatus;
use App\Enums\SyncLogType;
use App\Filament\Resources\SyncLogResource\Pages;
use App\Models\SyncLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class SyncLogResource extends Resource
{
    protected static ?string $model = SyncLog::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrow-path';

    protected static string | \UnitEnum | null $navigationGroup = 'Система';

    protected static ?string $modelLabel = 'синхронізація';

    protected static ?string $pluralModelLabel = 'Синхронізації';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof SyncLogType ? $state->label() : (string) $state),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn ($state): string => $state === SyncLogStatus::Success ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state): string => $state instanceof SyncLogStatus ? $state->label() : (string) $state),
                TextColumn::make('records_processed')
                    ->label('Записів')
                    ->sortable(),
                TextColumn::make('error_message')
                    ->label('Помилка')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('started_at')
                    ->label('Початок')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label('Кінець')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(collect(SyncLogType::cases())->mapWithKeys(fn (SyncLogType $t) => [$t->value => $t->label()])->all()),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(collect(SyncLogStatus::cases())->mapWithKeys(fn (SyncLogStatus $s) => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSyncLogs::route('/'),
            'view' => ViewSyncLog::route('/{record}'),
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
