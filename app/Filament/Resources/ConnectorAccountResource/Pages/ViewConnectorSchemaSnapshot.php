<?php

namespace App\Filament\Resources\ConnectorAccountResource\Pages;

use App\Enums\ConnectorSchemaFieldDisposition;
use App\Enums\SyncDataDomain;
use App\Filament\Resources\ConnectorAccountResource;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaFieldClassification;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Connectors\ConnectorAccountCapabilityPresentation;
use App\Support\Connectors\ConnectorAuthorization;
use App\Support\Connectors\ConnectorSchemaFieldPresenter;
use App\Support\Connectors\ConnectorSchemaFieldReadinessPresenter;
use App\Support\Connectors\ConnectorUiFormatter;
use App\Support\Workspace\WorkspaceContext;
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
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class ViewConnectorSchemaSnapshot extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ConnectorAccountResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.resources.connector-account-resource.pages.view-connector-schema-snapshot';

    public ConnectorAccount $account;

    public ?string $sourceLabel;

    public ?string $capturedAt;

    public int $fieldCount;

    public ?string $snapshotStateLabel;

    /** @var list<array{code: string, label: string, count: int}> */
    public array $classificationSummary = [];

    public bool $layerBPresentation = false;

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

    #[Url(as: 'configuration')]
    public ?string $configurationId = null;

    protected bool $configurationContextResolved = false;

    protected ?string $resolvedConfigurationId = null;

    public function getTitle(): string|Htmlable
    {
        return __('sync_mappings.available_fields_title', ['platform' => $this->account->connectorDefinition->name]);
    }

    public static function canAccess(array $parameters = []): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        return app(ConnectorAuthorization::class)->canLayerBExternalFieldReference(
            $user,
            app(WorkspaceContext::class)->current(),
        );
    }

    public static function authorizeResourceAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function mount(int|string $record, string $snapshot): void
    {
        $this->account = $this->resolveAccountRecord($record);
        $this->accountId = (string) $this->account->getKey();

        $queryConfiguration = request()->query('configuration');
        if ($this->configurationId === null && is_string($queryConfiguration) && $queryConfiguration !== '') {
            $this->configurationId = $queryConfiguration;
        }

        $snapshotRecord = $this->resolveSnapshotRecord($snapshot);
        $this->snapshotId = (string) $snapshotRecord->getKey();

        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $this->layerBPresentation = true;
        $this->sourceLabel = null;
        $this->capturedAt = ConnectorUiFormatter::formatDateTime($snapshotRecord->captured_at);
        $this->fieldCount = $snapshotRecord->field_count;
        $this->snapshotStateLabel = null;
        $this->loadClassificationSummary();
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
                TextColumn::make('classification_disposition')
                    ->label(__('connectors.ui.snapshot.fields.columns.disposition'))
                    ->getStateUsing(fn (ConnectorSchemaSnapshotField $record): string => ConnectorSchemaFieldReadinessPresenter::dispositionLabel(
                        $record->getAttribute('classification_disposition'),
                    )),
                TextColumn::make('mapping_readiness')
                    ->label(__('connectors.ui.snapshot.fields.columns.readiness'))
                    ->getStateUsing(fn (ConnectorSchemaSnapshotField $record): string => ConnectorSchemaFieldReadinessPresenter::readinessLabel(
                        $this->readinessCodeForRecord($record),
                    )),
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
                TextEntry::make('classification_disposition')
                    ->label(__('connectors.ui.snapshot.fields.columns.disposition'))
                    ->getStateUsing(fn (ConnectorSchemaSnapshotField $record): string => ConnectorSchemaFieldReadinessPresenter::dispositionLabel(
                        $record->getAttribute('classification_disposition'),
                    )),
                TextEntry::make('mapping_readiness')
                    ->label(__('connectors.ui.snapshot.fields.columns.readiness'))
                    ->getStateUsing(fn (ConnectorSchemaSnapshotField $record): string => ConnectorSchemaFieldReadinessPresenter::readinessLabel(
                        $this->readinessCodeForRecord($record),
                    )),
                TextEntry::make('classification_behavior_class')
                    ->label(__('connectors.ui.snapshot.fields.columns.behavior_class'))
                    ->placeholder(__('connectors.ui.common.dash')),
                TextEntry::make('classification_reason_code')
                    ->label(__('connectors.ui.snapshot.fields.columns.reason'))
                    ->placeholder(__('connectors.ui.common.dash')),
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
        $query = ConnectorSchemaSnapshotField::query()
            ->select([
                'connector_schema_snapshot_fields.id',
                'connector_schema_snapshot_fields.external_field_key',
                'connector_schema_snapshot_fields.external_label',
                'connector_schema_snapshot_fields.normalization_status',
                'connector_schema_snapshot_fields.normalized_data_type',
                'connector_schema_snapshot_fields.is_required',
                'connector_schema_snapshot_fields.is_multi_value',
                'connector_schema_snapshot_fields.is_localizable',
                'connector_schema_snapshot_fields.external_scope',
                'connector_schema_snapshot_fields.sort_order',
            ])
            ->addSelect([
                'classification_disposition' => ConnectorSchemaFieldClassification::withoutWorkspaceScope()
                    ->select('disposition')
                    ->whereColumn('latest_snapshot_field_id', 'connector_schema_snapshot_fields.id')
                    ->limit(1),
                'classification_mapping_strategy' => ConnectorSchemaFieldClassification::withoutWorkspaceScope()
                    ->select('mapping_strategy')
                    ->whereColumn('latest_snapshot_field_id', 'connector_schema_snapshot_fields.id')
                    ->limit(1),
                'classification_behavior_class' => ConnectorSchemaFieldClassification::withoutWorkspaceScope()
                    ->select('behavior_class')
                    ->whereColumn('latest_snapshot_field_id', 'connector_schema_snapshot_fields.id')
                    ->limit(1),
                'classification_reason_code' => ConnectorSchemaFieldClassification::withoutWorkspaceScope()
                    ->select('reason_code')
                    ->whereColumn('latest_snapshot_field_id', 'connector_schema_snapshot_fields.id')
                    ->limit(1),
            ])
            ->where('snapshot_id', $this->snapshotId)
            ->whereHas(
                'snapshot',
                fn (Builder $query): Builder => $query
                    ->whereKey($this->snapshotId)
                    ->where('connector_account_id', $this->accountId),
            );

        $configurationId = $this->effectiveMappingConfigurationId();

        if ($configurationId !== null) {
            $query->addSelect([
                'active_mapping_id' => FieldMapping::withoutWorkspaceScope()
                    ->select('id')
                    ->where('sync_configuration_id', $configurationId)
                    ->whereColumn('external_field_key', 'connector_schema_snapshot_fields.external_field_key')
                    ->limit(1),
            ]);
        } else {
            $query->selectRaw('NULL AS active_mapping_id');
        }

        return $query;
    }

    private function readinessCodeForRecord(ConnectorSchemaSnapshotField $record): string
    {
        return ConnectorSchemaFieldReadinessPresenter::readinessCode(
            $record->getAttribute('classification_disposition'),
            $record->getAttribute('classification_mapping_strategy'),
            $record->getAttribute('active_mapping_id') !== null,
            $this->effectiveMappingConfigurationId() !== null,
        );
    }

    private function effectiveMappingConfigurationId(): ?string
    {
        if ($this->configurationContextResolved) {
            return $this->resolvedConfigurationId;
        }

        $this->configurationContextResolved = true;
        if ($this->configurationId === null || $this->configurationId === '') {
            return null;
        }

        $this->resolvedConfigurationId = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $this->account->workspace_id)
            ->where('connector_account_id', $this->accountId)
            ->where('data_domain', SyncDataDomain::Products->value)
            ->whereKey($this->configurationId)
            ->value('id');

        return $this->resolvedConfigurationId;
    }

    private function loadClassificationSummary(): void
    {
        $fieldIds = ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('workspace_id', $this->account->workspace_id)
            ->where('snapshot_id', $this->snapshotId)
            ->pluck('id');

        if ($fieldIds->isEmpty()) {
            $this->classificationSummary = [];

            return;
        }

        $counts = ConnectorSchemaFieldClassification::withoutWorkspaceScope()
            ->where('workspace_id', $this->account->workspace_id)
            ->where('connector_account_id', $this->accountId)
            ->whereIn('latest_snapshot_field_id', $fieldIds->all())
            ->selectRaw('disposition, COUNT(*) AS total')
            ->groupBy('disposition')
            ->pluck('total', 'disposition');

        $summary = [];
        $configurationId = $this->effectiveMappingConfigurationId();
        if ($configurationId !== null) {
            $externalKeys = ConnectorSchemaSnapshotField::withoutWorkspaceScope()
                ->where('workspace_id', $this->account->workspace_id)
                ->where('snapshot_id', $this->snapshotId)
                ->pluck('external_field_key');
            $mapped = FieldMapping::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configurationId)
                ->whereIn('external_field_key', $externalKeys->all())
                ->count();
            $summary[] = [
                'code' => 'mapped',
                'label' => ConnectorSchemaFieldReadinessPresenter::readinessLabel('mapped'),
                'count' => $mapped,
            ];
        }

        foreach (ConnectorSchemaFieldDisposition::cases() as $disposition) {
            $summary[] = [
                'code' => $disposition->value,
                'label' => ConnectorSchemaFieldReadinessPresenter::dispositionLabel($disposition->value),
                'count' => (int) ($counts[$disposition->value] ?? 0),
            ];
        }

        $this->classificationSummary = $summary;
    }

    protected function resolveAccountRecord(int|string $key): ConnectorAccount
    {
        $record = ConnectorAccountResource::getEloquentQuery()
            ->whereKey($key)
            ->firstOrFail();

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $workspace = $record->workspace ?? Workspace::query()->findOrFail($record->workspace_id);

        abort_unless($this->canAccessLayerBFields($user, $workspace, $record), 403);

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

    private function canAccessLayerBFields(User $user, Workspace $workspace, ConnectorAccount $record): bool
    {
        if ($record->workspace_id !== $workspace->id) {
            return false;
        }

        return app(ConnectorAuthorization::class)->canLayerBExternalFieldReference($user, $workspace);
    }

    protected function resolveSnapshotRecord(string $snapshotId): ConnectorSchemaSnapshot
    {
        $snapshot = ConnectorSchemaSnapshot::query()
            ->where('connector_account_id', $this->account->getKey())
            ->whereKey($snapshotId)
            ->with([
                'schemaSource:id,label',
                'previousSnapshot:id,canonical_hash,canonical_hash_version',
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
