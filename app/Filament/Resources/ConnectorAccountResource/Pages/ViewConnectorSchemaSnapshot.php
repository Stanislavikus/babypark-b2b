<?php

namespace App\Filament\Resources\ConnectorAccountResource\Pages;

use App\Filament\Resources\ConnectorAccountResource;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Connectors\ConnectorAccountCapabilityPresentation;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\ConnectorSchemaFieldPresenter;
use App\Support\Connectors\ConnectorUiFormatter;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class ViewConnectorSchemaSnapshot extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ConnectorAccountResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.resources.connector-account-resource.pages.view-connector-schema-snapshot';

    public ConnectorAccount $account;

    public string $sourceLabel;

    public ?string $capturedAt;

    public int $fieldCount;

    public ?string $snapshotStateLabel;

    #[Locked]
    public string $snapshotId;

    #[Locked]
    public string $accountId;

    /**
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    /**
     * @var ?string
     */
    #[Url(as: 'search')]
    public $tableSearch = '';

    #[Url(as: 'sort')]
    public ?string $tableSort = null;

    public function getTitle(): string|Htmlable
    {
        return __('connectors.ui.snapshot.title');
    }

    public function mount(int|string $record, string $snapshot): void
    {
        $this->account = $this->resolveAccountRecord($record);
        $this->accountId = (string) $this->account->getKey();
        $snapshotRecord = $this->resolveSnapshotRecord($snapshot);
        $this->snapshotId = (string) $snapshotRecord->getKey();

        $uiState = app(ConnectorAccountUiState::class);

        $this->sourceLabel = $uiState->schemaSourceLabel($snapshotRecord->schemaSource);
        $this->capturedAt = ConnectorUiFormatter::formatDateTime($snapshotRecord->captured_at);
        $this->fieldCount = $snapshotRecord->field_count;
        $this->snapshotStateLabel = $uiState->snapshotStateLabel($snapshotRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('connectors.ui.snapshot.fields.section_title'))
            ->query(fn (): Builder => $this->getFieldTableQuery())
            ->emptyStateHeading(__('connectors.ui.snapshot.fields.empty_heading'))
            ->emptyStateDescription(__('connectors.ui.snapshot.fields.empty_description'))
            ->paginated([20, 50, 100])
            ->defaultPaginationPageOption(20)
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderByRaw('CASE WHEN sort_order IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('sort_order')
                    ->orderBy('external_field_key');
            })
            ->searchable()
            ->columns([
                TextColumn::make('external_field_key')
                    ->label(__('connectors.ui.snapshot.fields.columns.field'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('external_label')
                    ->label(__('connectors.ui.snapshot.fields.columns.label'))
                    ->searchable()
                    ->placeholder(__('connectors.ui.common.dash')),
                TextColumn::make('normalized_data_type')
                    ->label(__('connectors.ui.snapshot.fields.columns.type'))
                    ->formatStateUsing(fn (?string $state): string => ConnectorSchemaFieldPresenter::normalizedDataTypeLabel($state))
                    ->sortable(),
                TextColumn::make('is_required')
                    ->label(__('connectors.ui.snapshot.fields.columns.required'))
                    ->getStateUsing(fn (ConnectorSchemaSnapshotField $record): string => ConnectorSchemaFieldPresenter::booleanLabel($record->is_required)),
                TextColumn::make('external_scope')
                    ->label(__('connectors.ui.snapshot.fields.columns.scope'))
                    ->getStateUsing(fn (ConnectorSchemaSnapshotField $record): string => ConnectorSchemaFieldPresenter::externalScopeLabel($record->external_scope))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('is_multi_value')
                    ->label(__('connectors.ui.snapshot.fields.columns.multi_value'))
                    ->getStateUsing(fn (ConnectorSchemaSnapshotField $record): string => ConnectorSchemaFieldPresenter::booleanLabel($record->is_multi_value))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('is_localizable')
                    ->label(__('connectors.ui.snapshot.fields.columns.localizable'))
                    ->getStateUsing(fn (ConnectorSchemaSnapshotField $record): string => ConnectorSchemaFieldPresenter::booleanLabel($record->is_localizable))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('normalized_data_type')
                    ->label(__('connectors.ui.snapshot.fields.filters.type'))
                    ->options(ConnectorSchemaFieldPresenter::normalizedDataTypeOptions()),
                SelectFilter::make('is_required')
                    ->label(__('connectors.ui.snapshot.fields.filters.required'))
                    ->options([
                        'yes' => __('connectors.ui.snapshot.fields.boolean.yes'),
                        'no' => __('connectors.ui.snapshot.fields.boolean.no'),
                        'unknown' => __('connectors.ui.snapshot.fields.boolean.unknown'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'yes' => $query->where('is_required', true),
                            'no' => $query->where('is_required', false),
                            'unknown' => $query->whereNull('is_required'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('external_scope')
                    ->label(__('connectors.ui.snapshot.fields.filters.scope'))
                    ->options(ConnectorSchemaFieldPresenter::externalScopeOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            '__unknown__' => $query->whereNull('external_scope'),
                            default => filled($data['value'] ?? null)
                                ? $query->where('external_scope', $data['value'])
                                : $query,
                        };
                    }),
            ])
            ->filtersLayout(FiltersLayout::Modal)
            ->filtersTriggerAction(fn (Action $action) => $action->slideOver())
            ->filtersFormWidth('md')
            ->recordUrl(null)
            ->recordAction('view')
            ->recordActions([
                ViewAction::make()
                    ->slideOver()
                    ->modalWidth(Width::Medium)
                    ->modalHeading(__('connectors.ui.snapshot.fields.detail.title'))
                    ->schema($this->fieldDetailSchema(...)),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }

    /**
     * @return list<Component>
     */
    protected function fieldDetailSchema(): array
    {
        return [
            Section::make()->schema([
                TextEntry::make('external_field_key')
                    ->label(__('connectors.ui.snapshot.fields.detail.field_key')),
                TextEntry::make('external_label')
                    ->label(__('connectors.ui.snapshot.fields.detail.label'))
                    ->placeholder(__('connectors.ui.common.dash')),
                TextEntry::make('normalized_data_type')
                    ->label(__('connectors.ui.snapshot.fields.detail.type'))
                    ->formatStateUsing(fn (?string $state): string => ConnectorSchemaFieldPresenter::normalizedDataTypeLabel($state)),
                TextEntry::make('is_required')
                    ->label(__('connectors.ui.snapshot.fields.detail.required'))
                    ->getStateUsing(fn (ConnectorSchemaSnapshotField $record): string => ConnectorSchemaFieldPresenter::booleanLabel($record->is_required)),
                TextEntry::make('is_multi_value')
                    ->label(__('connectors.ui.snapshot.fields.detail.multi_value'))
                    ->getStateUsing(fn (ConnectorSchemaSnapshotField $record): string => ConnectorSchemaFieldPresenter::booleanLabel($record->is_multi_value)),
                TextEntry::make('is_localizable')
                    ->label(__('connectors.ui.snapshot.fields.detail.localizable'))
                    ->getStateUsing(fn (ConnectorSchemaSnapshotField $record): string => ConnectorSchemaFieldPresenter::booleanLabel($record->is_localizable)),
                TextEntry::make('external_scope')
                    ->label(__('connectors.ui.snapshot.fields.detail.scope'))
                    ->formatStateUsing(fn (?string $state): string => ConnectorSchemaFieldPresenter::externalScopeLabel($state)),
                TextEntry::make('sort_order')
                    ->label(__('connectors.ui.snapshot.fields.detail.sort_order'))
                    ->formatStateUsing(fn (?int $state): ?string => ConnectorSchemaFieldPresenter::sortOrderLabel($state))
                    ->placeholder(__('connectors.ui.common.dash'))
                    ->visible(fn (?int $state): bool => $state !== null),
            ])->columns(1),
        ];
    }

    protected function getFieldTableQuery(): Builder
    {
        return ConnectorSchemaSnapshotField::query()
            ->select([
                'id',
                'external_field_key',
                'external_label',
                'normalized_data_type',
                'is_required',
                'is_multi_value',
                'is_localizable',
                'external_scope',
                'sort_order',
            ])
            ->where('snapshot_id', $this->snapshotId)
            ->whereHas(
                'snapshot',
                fn (Builder $query): Builder => $query
                    ->whereKey($this->snapshotId)
                    ->where('connector_account_id', $this->accountId),
            );
    }

    protected function resolveAccountRecord(int|string $key): ConnectorAccount
    {
        $record = ConnectorAccountResource::getEloquentQuery()
            ->whereKey($key)
            ->firstOrFail();

        abort_unless(auth()->user()?->can('view', $record), 403);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $workspace = $record->workspace ?? Workspace::query()->findOrFail($record->workspace_id);
        $presentation = app(ConnectorAccountCapabilityPresentation::class);

        $record = $presentation->sanitizeRecord($record, $user, $workspace);

        if ($presentation->canManage($user, $workspace)) {
            $record->makeHidden([
                'credentials',
                'settings',
                'base_url',
                'auth_profile',
            ]);
        }

        return $record;
    }

    protected function resolveSnapshotRecord(string $snapshotId): ConnectorSchemaSnapshot
    {
        $snapshot = ConnectorSchemaSnapshot::query()
            ->where('connector_account_id', $this->account->getKey())
            ->whereKey($snapshotId)
            ->with([
                'schemaSource:id,label',
                'previousSnapshot:id,canonical_hash',
            ])
            ->first();

        if ($snapshot === null) {
            throw (new ModelNotFoundException)->setModel(ConnectorSchemaSnapshot::class, [$snapshotId]);
        }

        $snapshot->makeHidden([
            'canonical_hash',
        ]);

        if ($snapshot->schemaSource !== null) {
            $snapshot->schemaSource->makeHidden([
                'endpoint_path',
            ]);
        }

        if ($snapshot->previousSnapshot !== null) {
            $snapshot->previousSnapshot->makeHidden([
                'canonical_hash',
            ]);
        }

        return $snapshot;
    }
}
