<?php

namespace App\Filament\Resources;

use App\Enums\SyncLogStatus;
use App\Enums\SyncLogType;
use App\Filament\Resources\SyncLogResource\Pages;
use App\Models\SyncLog;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SyncLogResource extends Resource
{
    protected static ?string $model = SyncLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Система';

    protected static ?string $modelLabel = 'синхронізація';

    protected static ?string $pluralModelLabel = 'Синхронізації';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof SyncLogType ? $state->label() : (string) $state),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn ($state): string => $state === SyncLogStatus::Success ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state): string => $state instanceof SyncLogStatus ? $state->label() : (string) $state),
                Tables\Columns\TextColumn::make('records_processed')
                    ->label('Записів')
                    ->sortable(),
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Помилка')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('started_at')
                    ->label('Початок')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('finished_at')
                    ->label('Кінець')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Тип')
                    ->options(collect(SyncLogType::cases())->mapWithKeys(fn (SyncLogType $t) => [$t->value => $t->label()])->all()),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(collect(SyncLogStatus::cases())->mapWithKeys(fn (SyncLogStatus $s) => [$s->value => $s->label()])->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncLogs::route('/'),
            'view' => Pages\ViewSyncLog::route('/{record}'),
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

    public static function canDelete($record): bool
    {
        return false;
    }
}
