<?php

namespace App\Filament\Resources;

use App\Enums\ConnectorConnectionCheckStatus;
use App\Filament\Resources\ConnectorAccountResource\Pages;
use App\Models\ConnectorAccount;
use App\Models\User;
use App\Support\Connectors\ConnectorAccountMerchandiserPresentation;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\ConnectorUiFormatter;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ConnectorAccountResource extends Resource
{
    protected static ?string $model = ConnectorAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('connectors.ui.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('connectors.ui.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('connectors.ui.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('connectors.ui.resource.plural_model_label');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = ConnectorAccountMerchandiserPresentation::applySafeQuery(
            parent::getEloquentQuery(),
            auth()->user(),
        );

        return static::applyPresentationEagerLoads($query);
    }

    public static function loadAccountPresentationRelations(Model $record, ?User $user = null): Model
    {
        if ($record instanceof ConnectorAccount) {
            $relations = ['connectorDefinition'];

            if (! ConnectorAccountMerchandiserPresentation::isMerchandiser($user ?? auth()->user())) {
                $relations['connectionChecks'] = fn ($query) => $query
                    ->select(['id', 'connector_account_id', 'status'])
                    ->whereIn('status', [
                        ConnectorConnectionCheckStatus::Queued,
                        ConnectorConnectionCheckStatus::Running,
                    ]);
            }

            $record->loadMissing($relations);
        }

        return $record;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $uiState = app(ConnectorAccountUiState::class);

        return $infolist
            ->schema([
                Infolists\Components\Section::make(__('connectors.ui.sections.account'))
                    ->schema([
                        Infolists\Components\TextEntry::make('connectorDefinition.name')
                            ->label(__('connectors.ui.columns.platform'))
                            ->formatStateUsing(fn (?string $state, ConnectorAccount $record): string => filled($state)
                                ? $state.' ('.$record->connectorDefinition?->code.')'
                                : ($record->connectorDefinition?->code ?? __('connectors.ui.common.dash'))),
                        Infolists\Components\TextEntry::make('name')
                            ->label(__('connectors.ui.columns.account')),
                        Infolists\Components\TextEntry::make('store_code')
                            ->label(__('connectors.ui.columns.store_context'))
                            ->formatStateUsing(fn ($state, ConnectorAccount $record): string => $uiState->storeContextLabel($record) ?? __('connectors.ui.common.dash'))
                            ->visible(fn (): bool => ! ConnectorAccountMerchandiserPresentation::isMerchandiser(auth()->user())),
                        Infolists\Components\View::make('filament.connector-accounts.runtime-state')
                            ->label(__('connectors.ui.columns.status'))
                            ->viewData(fn (ConnectorAccount $record): array => [
                                'record' => $record,
                                'uiState' => $uiState,
                            ]),
                        Infolists\Components\TextEntry::make('last_checked_at')
                            ->label(__('connectors.ui.columns.last_check'))
                            ->formatStateUsing(fn ($state): ?string => ConnectorUiFormatter::formatDateTime($state))
                            ->placeholder(__('connectors.ui.common.dash')),
                        Infolists\Components\TextEntry::make('last_successful_check_at')
                            ->label(__('connectors.ui.columns.last_successful_check'))
                            ->formatStateUsing(fn ($state): ?string => ConnectorUiFormatter::formatDateTime($state))
                            ->placeholder(__('connectors.ui.common.dash')),
                        Infolists\Components\TextEntry::make('last_error_message_key')
                            ->label(__('connectors.ui.columns.attention'))
                            ->formatStateUsing(fn ($state, ConnectorAccount $record): ?string => $uiState->attentionMessage($record))
                            ->placeholder(__('connectors.ui.common.dash'))
                            ->visible(fn (ConnectorAccount $record): bool => $uiState->attentionMessage($record) !== null),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        $uiState = app(ConnectorAccountUiState::class);

        return $table
            ->poll('5s')
            ->columns([
                Tables\Columns\TextColumn::make('connectorDefinition.name')
                    ->label(__('connectors.ui.columns.platform'))
                    ->description(fn (ConnectorAccount $record): ?string => $record->connectorDefinition?->code)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas(
                            'connectorDefinition',
                            fn (Builder $definitionQuery) => $definitionQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%"),
                        );
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('connectors.ui.columns.account'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('store_code')
                    ->label(__('connectors.ui.columns.store_context'))
                    ->formatStateUsing(fn ($state, ConnectorAccount $record): string => $uiState->storeContextLabel($record) ?? __('connectors.ui.common.dash'))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        if (ConnectorAccountMerchandiserPresentation::isMerchandiser(auth()->user())) {
                            return $query;
                        }

                        return $query
                            ->where('store_code', 'like', "%{$search}%")
                            ->orWhere('tenant_context', 'like', "%{$search}%");
                    })
                    ->visible(fn (): bool => ! ConnectorAccountMerchandiserPresentation::isMerchandiser(auth()->user()))
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('connection_status')
                    ->label(__('connectors.ui.columns.status'))
                    ->html()
                    ->formatStateUsing(function ($state, ConnectorAccount $record) use ($uiState): string {
                        $activeCheck = $uiState->activeConnectionCheck($record);

                        if ($activeCheck !== null) {
                            $runtimeLabel = e($uiState->runtimeStatusLabel($activeCheck));
                            $stableLabel = e($uiState->stableStatusLabel($record->connection_status));

                            return '<div class="space-y-1">'
                                .'<span class="inline-flex items-center rounded-md bg-info-50 px-2 py-1 text-xs font-medium text-info-700 ring-1 ring-inset ring-info-600/20 dark:bg-info-400/10 dark:text-info-400">'.$runtimeLabel.'</span>'
                                .'<div class="text-xs text-gray-500 dark:text-gray-400">'.e(__('connectors.ui.runtime.last_result_prefix')).' '.$stableLabel.'</div>'
                                .'</div>';
                        }

                        $label = e($uiState->stableStatusLabel($record->connection_status));
                        $color = $uiState->stableStatusColor($record->connection_status);

                        return '<span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset '
                            .match ($color) {
                                'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400',
                                'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400',
                                'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400',
                                default => 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-400',
                            }
                        .'">'.$label.'</span>';
                    }),
                Tables\Columns\TextColumn::make('last_checked_at')
                    ->label(__('connectors.ui.columns.last_check'))
                    ->formatStateUsing(fn ($state): ?string => ConnectorUiFormatter::formatDateTime($state))
                    ->placeholder(__('connectors.ui.common.dash'))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_successful_check_at')
                    ->label(__('connectors.ui.columns.last_successful_check'))
                    ->formatStateUsing(fn ($state): ?string => ConnectorUiFormatter::formatDateTime($state))
                    ->placeholder(__('connectors.ui.common.dash'))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_error_message_key')
                    ->label(__('connectors.ui.columns.attention'))
                    ->formatStateUsing(fn ($state, ConnectorAccount $record): ?string => $uiState->attentionMessage($record))
                    ->placeholder(__('connectors.ui.common.dash'))
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConnectorAccounts::route('/'),
            'view' => Pages\ViewConnectorAccount::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    private static function applyPresentationEagerLoads(Builder $query): Builder
    {
        $with = ['connectorDefinition'];

        if (! ConnectorAccountMerchandiserPresentation::isMerchandiser(auth()->user())) {
            $with['connectionChecks'] = fn ($connectionChecksQuery) => $connectionChecksQuery
                ->select(['id', 'connector_account_id', 'status'])
                ->whereIn('status', [
                    ConnectorConnectionCheckStatus::Queued,
                    ConnectorConnectionCheckStatus::Running,
                ]);
        }

        return $query->with($with);
    }
}
