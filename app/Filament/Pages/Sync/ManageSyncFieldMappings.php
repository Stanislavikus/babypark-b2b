<?php

namespace App\Filament\Pages\Sync;

use App\Filament\Resources\ConnectorAccountResource;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\AuthoritativeConnectorSchemaSnapshotResolver;
use App\Services\Sync\FieldMappingAuthorizationService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Connectors\ConnectorAccountCapabilityPresentation;
use App\Support\Connectors\ConnectorAuthorization;
use App\Support\Sync\Exceptions\FieldMappingConflictException;
use App\Support\Sync\Exceptions\FieldMappingValidationException;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\FieldMappingPresentation\FieldMappingSafeMessagePresenter;
use App\Support\Sync\FieldMappingPresentation\SyncFieldMappingRowPresenter;
use App\Support\Sync\FieldMappingPresentation\SyncFieldMappingRowState;
use App\Support\Sync\FieldMappingReadModel\DiscoveredExternalFieldChoice;
use App\Support\Sync\FieldMappingReadModel\FieldMappingInternalRow;
use App\Support\Sync\FieldMappingReadModel\FieldMappingReadModel;
use App\Support\Workspace\Rbac\Concerns\RequiresFreshWorkspaceMappingPermission;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class ManageSyncFieldMappings extends Page implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use RequiresFreshWorkspaceMappingPermission;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'sync-mappings/{account}/{configuration}';

    protected string $view = 'filament.pages.sync.manage-sync-field-mappings';

    #[Locked]
    public string $accountId;

    #[Locked]
    public string $configurationId;

    public string $platformName = '';

    public string $accountName = '';

    public bool $discoveryAvailable = false;

    public bool $canMutate = false;

    public ?string $availableFieldsUrl = null;

    #[Url(as: 'search')]
    public ?string $search = '';

    #[Url(as: 'status')]
    public ?string $statusFilter = 'all';

    /** @var list<array<string, mixed>> */
    public array $displayRows = [];

    public ?string $progressSummary = null;

    /** @var array<string, string> */
    public array $externalFieldOptions = [];

    public static function canAccess(array $parameters = []): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && app(ConnectorAuthorization::class)->canReadSyncMappings(
                $user,
                app(WorkspaceContext::class)->current(),
            );
    }

    public function getTitle(): string|Htmlable
    {
        return __('sync_mappings.title');
    }

    public function mount(string $account, string $configuration): void
    {
        $workspace = $this->resolveMappingWorkspace();
        $resolvedAccount = $this->resolveAccount($account, $workspace);

        $this->accountId = (string) $resolvedAccount->getKey();

        if (! $this->syncConfigurationBelongsToAccount($workspace, $resolvedAccount, $configuration)) {
            abort(404);
        }

        $this->configurationId = $configuration;
        $this->accountName = $resolvedAccount->name;
        $this->platformName = $resolvedAccount->connectorDefinition->name;

        $this->refreshReadModel();
    }

    public function updatedSearch(): void
    {
        $this->refreshDisplayRows();
    }

    public function updatedStatusFilter(): void
    {
        $this->refreshDisplayRows();
    }

    public function confirmMapping(string $fieldBindingId, string $externalFieldKey): void
    {
        if (! $this->canMutate || ! $this->discoveryAvailable) {
            abort(403);
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            app(FieldMappingAuthorizationService::class)->confirm(
                $user,
                $this->resolveMappingWorkspace()->id,
                $this->accountId,
                $this->configurationId,
                $fieldBindingId,
                $externalFieldKey,
            );

            Notification::make()
                ->title(__('sync_mappings.notifications.confirmed'))
                ->success()
                ->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (FieldMappingConflictException|FieldMappingValidationException $exception) {
            $this->notifyMappingFailure($exception);
        }

        $this->refreshReadModel();
    }

    public function removeMapping(string $fieldBindingId, string $externalFieldKey): void
    {
        if (! $this->canMutate || ! $this->discoveryAvailable) {
            abort(403);
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            app(FieldMappingAuthorizationService::class)->remove(
                $user,
                $this->resolveMappingWorkspace()->id,
                $this->accountId,
                $this->configurationId,
                $fieldBindingId,
                $externalFieldKey,
            );

            Notification::make()
                ->title(__('sync_mappings.notifications.removed'))
                ->success()
                ->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (FieldMappingConflictException|FieldMappingValidationException $exception) {
            $this->notifyMappingFailure($exception);
        }

        $this->refreshReadModel();
    }

    public function changeMappingAction(): Action
    {
        return Action::make('changeMapping')
            ->modalHeading(__('sync_mappings.actions.change_heading'))
            ->schema([
                Select::make('external_field_key')
                    ->label(__('sync_mappings.columns.platform_field'))
                    ->options(fn (): array => $this->externalFieldOptions)
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                if (! $this->canMutate || ! $this->discoveryAvailable) {
                    abort(403);
                }

                $user = Auth::user();
                abort_unless($user instanceof User, 403);

                $fieldBindingId = $arguments['fieldBindingId'] ?? '';
                $currentExternalFieldKey = $arguments['externalFieldKey'] ?? '';
                $newExternalFieldKey = $data['external_field_key'] ?? '';

                try {
                    if ($currentExternalFieldKey === '') {
                        app(FieldMappingAuthorizationService::class)->confirm(
                            $user,
                            $this->resolveMappingWorkspace()->id,
                            $this->accountId,
                            $this->configurationId,
                            $fieldBindingId,
                            $newExternalFieldKey,
                        );
                    } else {
                        app(FieldMappingAuthorizationService::class)->replace(
                            $user,
                            $this->resolveMappingWorkspace()->id,
                            $this->accountId,
                            $this->configurationId,
                            $fieldBindingId,
                            $currentExternalFieldKey,
                            newExternalFieldKey: $newExternalFieldKey,
                        );
                    }

                    Notification::make()
                        ->title(__('sync_mappings.notifications.changed'))
                        ->success()
                        ->send();
                } catch (AuthorizationException) {
                    abort(403);
                } catch (FieldMappingConflictException|FieldMappingValidationException $exception) {
                    $this->notifyMappingFailure($exception);
                }

                $this->refreshReadModel();
            });
    }

    protected function refreshReadModel(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $workspace = $this->resolveMappingWorkspace();

        $this->canMutate = $this->workspaceAllowsMutation($user, $workspace);

        try {
            $readModel = app(FieldMappingAuthorizationService::class)->projectReadModel(
                $user,
                $workspace->id,
                $this->accountId,
                $this->configurationId,
            );
        } catch (SyncConfigurationNotFoundException) {
            abort(404);
        }

        $this->discoveryAvailable = $readModel->discoveryAvailable;
        $this->externalFieldOptions = $this->buildExternalFieldOptions($readModel);
        $this->availableFieldsUrl = $this->buildAvailableFieldsUrl($workspace);

        $this->refreshDisplayRows($readModel);
    }

    protected function refreshDisplayRows(?FieldMappingReadModel $readModel = null): void
    {
        if ($readModel === null) {
            $user = Auth::user();
            abort_unless($user instanceof User, 403);

            $workspace = $this->resolveMappingWorkspace();

            try {
                $readModel = app(FieldMappingAuthorizationService::class)->projectReadModel(
                    $user,
                    $workspace->id,
                    $this->accountId,
                    $this->configurationId,
                );
            } catch (SyncConfigurationNotFoundException) {
                abort(404);
            }
        }

        $presenter = app(SyncFieldMappingRowPresenter::class);
        $labelsByKey = $this->externalLabelsByKey($readModel);

        $rows = [];

        foreach ($readModel->internalRows as $row) {
            $semanticState = $presenter->semanticState($row, $readModel->discoveryAvailable);

            if (! $this->matchesStatusFilter($semanticState)) {
                continue;
            }

            if (! $this->matchesSearch($row, $presenter, $readModel->discoveryAvailable, $labelsByKey)) {
                continue;
            }

            $rows[] = $this->serializeRow($row, $semanticState, $presenter, $readModel->discoveryAvailable, $labelsByKey);
        }

        $this->displayRows = $rows;
        $this->progressSummary = $this->buildProgressSummary($readModel);
    }

    /**
     * @return array<string, string>
     */
    protected function buildExternalFieldOptions(FieldMappingReadModel $readModel): array
    {
        $options = [];

        foreach ($readModel->discoveredExternalChoices as $choice) {
            $options[$choice->externalFieldKey] = $this->formatExternalChoiceLabel($choice);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected function externalLabelsByKey(FieldMappingReadModel $readModel): array
    {
        $labels = [];

        foreach ($readModel->discoveredExternalChoices as $choice) {
            $labels[$choice->externalFieldKey] = $this->formatExternalChoiceLabel($choice);
        }

        return $labels;
    }

    protected function formatExternalChoiceLabel(DiscoveredExternalFieldChoice $choice): string
    {
        if ($choice->externalLabel !== '') {
            return $choice->externalLabel;
        }

        return $choice->externalFieldKey;
    }

    protected function buildAvailableFieldsUrl(Workspace $workspace): ?string
    {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($this->accountId)
            ->first();

        if ($account === null) {
            return null;
        }

        $snapshot = app(AuthoritativeConnectorSchemaSnapshotResolver::class)->resolveSnapshot($account);

        if ($snapshot === null) {
            return null;
        }

        return ConnectorAccountResource::getUrl('view-snapshot', [
            'record' => $this->accountId,
            'snapshot' => $snapshot->id,
        ]);
    }

    protected function buildProgressSummary(FieldMappingReadModel $readModel): string
    {
        $presenter = app(SyncFieldMappingRowPresenter::class);
        $mapped = 0;
        $total = count($readModel->internalRows);

        foreach ($readModel->internalRows as $row) {
            if ($presenter->semanticState($row, $readModel->discoveryAvailable) === SyncFieldMappingRowState::MAPPED) {
                $mapped++;
            }
        }

        return __('sync_mappings.progress_summary', [
            'mapped' => $mapped,
            'total' => $total,
        ]);
    }

    /**
     * @param  array<string, string>  $labelsByKey
     * @return array<string, mixed>
     */
    protected function serializeRow(
        FieldMappingInternalRow $row,
        string $semanticState,
        SyncFieldMappingRowPresenter $presenter,
        bool $discoveryAvailable,
        array $labelsByKey,
    ): array {
        $externalKey = $row->existingExternalFieldKey
            ?? ($discoveryAvailable ? $row->suggestedExternalFieldKey : null);

        return [
            'field_binding_id' => $row->fieldBindingId,
            'internal_label' => $row->label,
            'external_key' => $externalKey,
            'external_label' => $presenter->effectiveExternalLabel($row, $discoveryAvailable, $labelsByKey),
            'semantic_state' => $semanticState,
            'existing_external_field_key' => $row->existingExternalFieldKey,
            'suggested_external_field_key' => $row->suggestedExternalFieldKey,
            'status_label' => __("sync_mappings.status.{$semanticState}"),
            'status_icon' => match ($semanticState) {
                SyncFieldMappingRowState::MAPPED => 'check',
                SyncFieldMappingRowState::NEEDS_ATTENTION => 'warning',
                default => null,
            },
        ];
    }

    protected function matchesStatusFilter(string $semanticState): bool
    {
        return match ($this->statusFilter) {
            'needs_attention' => $semanticState === SyncFieldMappingRowState::NEEDS_ATTENTION,
            'mapped' => $semanticState === SyncFieldMappingRowState::MAPPED,
            'unmapped' => in_array($semanticState, [
                SyncFieldMappingRowState::UNMAPPED,
                SyncFieldMappingRowState::SUGGESTED,
            ], true),
            default => true,
        };
    }

    /**
     * @param  array<string, string>  $labelsByKey
     */
    protected function matchesSearch(
        FieldMappingInternalRow $row,
        SyncFieldMappingRowPresenter $presenter,
        bool $discoveryAvailable,
        array $labelsByKey,
    ): bool {
        $needle = mb_strtolower(trim((string) $this->search));

        if ($needle === '') {
            return true;
        }

        $externalLabel = $presenter->effectiveExternalLabel($row, $discoveryAvailable, $labelsByKey) ?? '';

        return str_contains(mb_strtolower($row->label), $needle)
            || str_contains(mb_strtolower($externalLabel), $needle)
            || str_contains(mb_strtolower((string) $row->existingExternalFieldKey), $needle)
            || str_contains(mb_strtolower((string) $row->suggestedExternalFieldKey), $needle);
    }

    protected function resolveAccount(string $accountId, Workspace $workspace): ConnectorAccount
    {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($accountId)
            ->with('connectorDefinition:id,name,code')
            ->first();

        if ($account === null) {
            abort(403);
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return app(ConnectorAccountCapabilityPresentation::class)->sanitizeRecord($account, $user, $workspace);
    }

    protected function workspaceAllowsMutation(User $user, Workspace $workspace): bool
    {
        return app(WorkspaceAuthorization::class)->allows(
            $user,
            $workspace,
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
        );
    }

    protected function syncConfigurationBelongsToAccount(
        Workspace $workspace,
        ConnectorAccount $account,
        string $configurationId,
    ): bool {
        return SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('connector_account_id', $account->id)
            ->whereKey($configurationId)
            ->exists();
    }

    protected function notifyMappingFailure(FieldMappingConflictException|FieldMappingValidationException $exception): void
    {
        $presenter = app(FieldMappingSafeMessagePresenter::class);
        $presenter->report($exception);

        Notification::make()
            ->title(__('sync_mappings.notifications.failed'))
            ->body($presenter->present($exception))
            ->danger()
            ->send();
    }
}
