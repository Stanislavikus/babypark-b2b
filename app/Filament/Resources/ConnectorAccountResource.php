<?php

namespace App\Filament\Resources;

use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Filament\Resources\ConnectorAccountResource\Pages\ListConnectorAccounts;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorSchemaSnapshot;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\User;
use App\Support\Connectors\ConnectorAccountMerchandiserPresentation;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\ConnectorUiFormatter;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ConnectorAccountResource extends Resource
{
    protected static ?string $model = ConnectorAccount::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static ?int $navigationSort = 3;

    /**
     * Merchant entry is Інтеграції (platform-first landing). This resource remains
     * the account Overview destination for adaptive 1-account / list-row opens.
     */
    protected static bool $shouldRegisterNavigation = false;

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

            $relations['discoveryRuns'] = fn ($query) => $query
                ->select(['id', 'connector_account_id', 'status'])
                ->whereIn('status', [
                    ConnectorDiscoveryRunStatus::Queued,
                    ConnectorDiscoveryRunStatus::Running,
                ]);

            $record->loadMissing($relations);

            $uiState = app(ConnectorAccountUiState::class);

            if ($record->last_discovery_at !== null || $uiState->hasActiveDiscoveryRun($record)) {
                $latestRun = ConnectorDiscoveryRun::query()
                    ->where('connector_account_id', $record->getKey())
                    ->with([
                        'schemaSource:id,label',
                        'snapshot' => fn ($snapshotQuery) => $snapshotQuery->select([
                            'id',
                            'connector_account_id',
                            'connector_schema_source_id',
                            'field_count',
                            'captured_at',
                            'previous_snapshot_id',
                            'canonical_hash',
                        ]),
                        'snapshot.schemaSource:id,label',
                        'snapshot.previousSnapshot:id,canonical_hash',
                    ])
                    ->latest('created_at')
                    ->first();

                if ($latestRun !== null) {
                    $latestRun->makeHidden([
                        'error_code',
                        'technical_summary',
                        'added_count',
                        'changed_count',
                        'removed_count',
                        'unchanged_count',
                    ]);

                    if ($latestRun->schemaSource !== null) {
                        $latestRun->schemaSource->makeHidden(['endpoint_path']);
                    }

                    if ($latestRun->snapshot !== null) {
                        $latestRun->snapshot->makeHidden(['canonical_hash']);

                        if ($latestRun->snapshot->schemaSource !== null) {
                            $latestRun->snapshot->schemaSource->makeHidden(['endpoint_path']);
                        }

                        if ($latestRun->snapshot->previousSnapshot !== null) {
                            $latestRun->snapshot->previousSnapshot->makeHidden(['canonical_hash']);
                        }
                    }

                    $record->setRelation('latestPresentationDiscoveryRun', $latestRun);
                }
            }
        }

        return $record;
    }

    public static function infolist(Schema $schema): Schema
    {
        $uiState = app(ConnectorAccountUiState::class);

        return $schema
            ->components([
                Section::make(__('connectors.ui.sections.account'))
                    ->schema([
                        TextEntry::make('connectorDefinition.name')
                            ->label(__('connectors.ui.columns.platform'))
                            ->formatStateUsing(fn (?string $state, ConnectorAccount $record): string => filled($state)
                                ? $state.' ('.$record->connectorDefinition?->code.')'
                                : ($record->connectorDefinition?->code ?? __('connectors.ui.common.dash'))),
                        TextEntry::make('name')
                            ->label(__('connectors.ui.columns.account')),
                        TextEntry::make('store_code')
                            ->label(__('connectors.ui.columns.store_context'))
                            ->formatStateUsing(fn ($state, ConnectorAccount $record): string => $uiState->storeContextLabel($record) ?? __('connectors.ui.common.dash'))
                            ->visible(fn (): bool => ! ConnectorAccountMerchandiserPresentation::isMerchandiser(auth()->user())),
                        ViewEntry::make('runtime_state')
                            ->label(__('connectors.ui.columns.status'))
                            ->view('filament.connector-accounts.runtime-state')
                            ->viewData(fn (ConnectorAccount $record): array => [
                                'record' => $record,
                                'uiState' => $uiState,
                                'showActiveConnectionCheck' => ConnectorAccountMerchandiserPresentation::showActiveConnectionCheck(auth()->user()),
                            ]),
                        TextEntry::make('last_checked_at')
                            ->label(__('connectors.ui.columns.last_check'))
                            ->formatStateUsing(fn ($state): ?string => ConnectorUiFormatter::formatDateTime($state))
                            ->placeholder(__('connectors.ui.common.dash')),
                        TextEntry::make('last_successful_check_at')
                            ->label(__('connectors.ui.columns.last_successful_check'))
                            ->formatStateUsing(fn ($state): ?string => ConnectorUiFormatter::formatDateTime($state))
                            ->placeholder(__('connectors.ui.common.dash')),
                        TextEntry::make('last_error_message_key')
                            ->label(__('connectors.ui.columns.attention'))
                            ->formatStateUsing(fn ($state, ConnectorAccount $record): ?string => $uiState->attentionMessage($record))
                            ->placeholder(__('connectors.ui.common.dash'))
                            ->visible(fn (ConnectorAccount $record): bool => $uiState->attentionMessage($record) !== null),
                    ])
                    ->columns(2),
                Section::make(__('connectors.ui.sections.discovery'))
                    ->schema([
                        ViewEntry::make('discovery_state')
                            ->label('')
                            ->view('filament.connector-accounts.discovery-state')
                            ->viewData(fn (ConnectorAccount $record): array => [
                                'record' => $record,
                                'uiState' => $uiState,
                                'latestRun' => $record->relationLoaded('latestPresentationDiscoveryRun')
                                    ? $record->getRelation('latestPresentationDiscoveryRun')
                                    : null,
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $uiState = app(ConnectorAccountUiState::class);

        return $table
            ->poll('5s')
            ->columns([
                TextColumn::make('connectorDefinition.name')
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
                TextColumn::make('name')
                    ->label(__('connectors.ui.columns.account'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('store_code')
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
                TextColumn::make('connection_status')
                    ->label(__('connectors.ui.columns.status'))
                    ->html()
                    ->formatStateUsing(function ($state, ConnectorAccount $record) use ($uiState): string {
                        $activeCheck = ConnectorAccountMerchandiserPresentation::showActiveConnectionCheck(auth()->user())
                            ? $uiState->activeConnectionCheck($record)
                            : null;

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
                TextColumn::make('last_checked_at')
                    ->label(__('connectors.ui.columns.last_check'))
                    ->formatStateUsing(fn ($state): ?string => ConnectorUiFormatter::formatDateTime($state))
                    ->placeholder(__('connectors.ui.common.dash'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_successful_check_at')
                    ->label(__('connectors.ui.columns.last_successful_check'))
                    ->formatStateUsing(fn ($state): ?string => ConnectorUiFormatter::formatDateTime($state))
                    ->placeholder(__('connectors.ui.common.dash'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_successful_discovery_at')
                    ->label(__('connectors.ui.columns.last_successful_discovery'))
                    ->formatStateUsing(fn ($state): ?string => ConnectorUiFormatter::formatDateTime($state))
                    ->placeholder(__('connectors.ui.common.dash'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_error_message_key')
                    ->label(__('connectors.ui.columns.attention'))
                    ->formatStateUsing(fn ($state, ConnectorAccount $record): ?string => $uiState->attentionMessage($record))
                    ->placeholder(__('connectors.ui.common.dash'))
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConnectorAccounts::route('/'),
            'view' => ViewConnectorAccount::route('/{record}'),
            'view-snapshot' => ViewConnectorSchemaSnapshot::route('/{record}/snapshots/{snapshot}'),
        ];
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

        $with['discoveryRuns'] = fn ($discoveryRunsQuery) => $discoveryRunsQuery
            ->select(['id', 'connector_account_id', 'status'])
            ->whereIn('status', [
                ConnectorDiscoveryRunStatus::Queued,
                ConnectorDiscoveryRunStatus::Running,
            ]);

        return $query->with($with);
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
