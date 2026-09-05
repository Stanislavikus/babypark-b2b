<?php

namespace App\Filament\Resources\ConnectorAccountResource\RelationManagers;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Filament\Resources\ConnectorAccountResource;
use App\Models\ConnectorDiscoveryRun;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\ConnectorUiFormatter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class DiscoveryRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'discoveryRuns';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('connectors.ui.relation.discovery_runs');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    #[On('refreshRelationManagers')]
    public function refreshRelationManagers(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $uiState = app(ConnectorAccountUiState::class);

        return $table
            ->emptyStateHeading(__('connectors.ui.discovery.history.empty_heading'))
            ->emptyStateDescription(__('connectors.ui.discovery.history.empty_description'))
            ->modifyQueryUsing(
                fn (Builder $query) => $query
                    ->select([
                        'id',
                        'workspace_id',
                        'connector_account_id',
                        'connector_schema_source_id',
                        'trigger',
                        'initiated_by_user_id',
                        'status',
                        'execution_attempts',
                        'duration_ms',
                        'user_message_key',
                        'snapshot_id',
                        'created_at',
                    ])
                    ->with([
                        'initiatedByUser',
                        'schemaSource:id,label',
                    ])
                    ->latest('created_at'),
            )
            ->poll(fn (): ?string => app(ConnectorAccountUiState::class)
                ->hasActiveDiscoveryRun($this->getOwnerRecord())
                ? '5s'
                : null)
            ->paginated([20, 50, 100])
            ->defaultPaginationPageOption(20)
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('connectors.ui.columns.discovery_at'))
                    ->formatStateUsing(fn ($state): ?string => ConnectorUiFormatter::formatDateTime($state))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('connectors.ui.columns.discovery_status'))
                    ->badge()
                    ->color(fn (ConnectorDiscoveryRunStatus $state): string => $uiState->discoveryStatusColor($state))
                    ->formatStateUsing(fn (ConnectorDiscoveryRunStatus $state): string => $uiState->discoveryStatusLabel($state)),
                TextColumn::make('schemaSource.label')
                    ->label(__('connectors.ui.columns.source'))
                    ->formatStateUsing(fn ($state, ConnectorDiscoveryRun $record): string => $uiState->schemaSourceLabel($record->schemaSource))
                    ->placeholder(__('connectors.ui.common.dash')),
                TextColumn::make('trigger')
                    ->label(__('connectors.ui.columns.trigger'))
                    ->formatStateUsing(fn ($state): string => $state ? __($state->label()) : __('connectors.ui.common.dash')),
                TextColumn::make('initiator')
                    ->label(__('connectors.ui.columns.initiator'))
                    ->getStateUsing(fn (ConnectorDiscoveryRun $record): string => $uiState->discoveryInitiatorLabel($record)),
                TextColumn::make('execution_attempts')
                    ->label(__('connectors.ui.columns.attempts'))
                    ->placeholder(__('connectors.ui.common.dash')),
                TextColumn::make('duration_ms')
                    ->label(__('connectors.ui.columns.duration'))
                    ->formatStateUsing(fn (?int $state): string => $uiState->formatDuration($state) ?? __('connectors.ui.common.dash')),
                TextColumn::make('safe_error')
                    ->label(__('connectors.ui.columns.attention'))
                    ->getStateUsing(fn (ConnectorDiscoveryRun $record): string => $uiState->discoveryErrorMessage($record) ?? __('connectors.ui.common.dash'))
                    ->placeholder(__('connectors.ui.common.dash')),
                TextColumn::make('snapshot_link')
                    ->label(__('connectors.ui.columns.snapshot'))
                    ->url(fn (ConnectorDiscoveryRun $record): ?string => $record->snapshot_id !== null
                        ? ConnectorAccountResource::getUrl('view-snapshot', [
                            'record' => $record->connector_account_id,
                            'snapshot' => $record->snapshot_id,
                        ])
                        : null)
                    ->formatStateUsing(fn ($state, ConnectorDiscoveryRun $record): string => $record->snapshot_id !== null
                        ? __('connectors.ui.snapshot.view_summary')
                        : __('connectors.ui.common.dash'))
                    ->color(fn (ConnectorDiscoveryRun $record): ?string => $record->snapshot_id !== null ? 'primary' : null),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
