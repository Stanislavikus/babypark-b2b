<?php

namespace App\Filament\Resources\ConnectorAccountResource\RelationManagers;

use App\Enums\ConnectorConnectionCheckStatus;
use App\Models\ConnectorConnectionCheck;
use App\Support\Connectors\ConnectorAccountUiState;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ConnectionChecksRelationManager extends RelationManager
{
    protected static string $relationship = 'connectionChecks';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('connectors.ui.relation.connection_checks');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $uiState = app(ConnectorAccountUiState::class);

        return $table
            ->modifyQueryUsing(
                fn (Builder $query) => $query
                    ->with('initiatedByUser')
                    ->latest('created_at'),
            )
            ->poll('5s')
            ->paginated([20, 50, 100])
            ->defaultPaginationPageOption(20)
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('connectors.ui.columns.checked_at'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('connectors.ui.columns.check_status'))
                    ->badge()
                    ->color(fn (ConnectorConnectionCheckStatus $state): string => match ($state) {
                        ConnectorConnectionCheckStatus::Succeeded => 'success',
                        ConnectorConnectionCheckStatus::Failed => 'danger',
                        ConnectorConnectionCheckStatus::Queued,
                        ConnectorConnectionCheckStatus::Running => 'info',
                    })
                    ->formatStateUsing(fn (ConnectorConnectionCheckStatus $state): string => __($state->label())),
                Tables\Columns\TextColumn::make('cause_category')
                    ->label(__('connectors.ui.columns.cause'))
                    ->formatStateUsing(fn ($state, ConnectorConnectionCheck $record): string => $record->status === ConnectorConnectionCheckStatus::Succeeded || $state === null
                        ? __('connectors.ui.common.dash')
                        : __($state->label()))
                    ->placeholder(__('connectors.ui.common.dash')),
                Tables\Columns\TextColumn::make('actionability')
                    ->label(__('connectors.ui.columns.action_required'))
                    ->formatStateUsing(fn ($state, ConnectorConnectionCheck $record): string => $record->status === ConnectorConnectionCheckStatus::Succeeded || $state === null
                        ? __('connectors.ui.common.dash')
                        : __($state->label()))
                    ->placeholder(__('connectors.ui.common.dash')),
                Tables\Columns\TextColumn::make('initiator')
                    ->label(__('connectors.ui.columns.initiator'))
                    ->getStateUsing(fn (ConnectorConnectionCheck $record): string => $uiState->initiatorLabel($record)),
                Tables\Columns\TextColumn::make('trigger')
                    ->label(__('connectors.ui.columns.trigger'))
                    ->formatStateUsing(fn ($state): string => $state ? __($state->label()) : __('connectors.ui.common.dash')),
                Tables\Columns\TextColumn::make('duration_ms')
                    ->label(__('connectors.ui.columns.duration'))
                    ->formatStateUsing(fn (?int $state): string => $uiState->formatDuration($state) ?? __('connectors.ui.common.dash')),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
