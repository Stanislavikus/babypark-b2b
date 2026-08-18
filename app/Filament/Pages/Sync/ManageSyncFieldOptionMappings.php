<?php

namespace App\Filament\Pages\Sync;

use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\FieldOptionMappingAuthorizationService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Connectors\ConnectorAccountCapabilityPresentation;
use App\Support\Connectors\ConnectorAuthorization;
use App\Support\Sync\Exceptions\FieldMappingValidationException;
use App\Support\Sync\Exceptions\FieldOptionMappingConflictException;
use App\Support\Sync\Exceptions\FieldOptionMappingStaleMutationException;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\FieldOptionMappingPresentation\FieldOptionMappingSafeMessagePresenter;
use App\Support\Sync\FieldOptionMappingReadModel\FieldOptionMappingNormalRow;
use App\Support\Sync\FieldOptionMappingReadModel\FieldOptionMappingReadModel;
use App\Support\Sync\FieldOptionMappingReadModel\FieldOptionMappingRowState;
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

class ManageSyncFieldOptionMappings extends Page implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use RequiresFreshWorkspaceMappingPermission;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'sync-mappings/{account}/{configuration}/{mapping}/options';

    protected string $view = 'filament.pages.sync.manage-sync-field-option-mappings';

    #[Locked]
    public string $accountId;

    #[Locked]
    public string $configurationId;

    #[Locked]
    public string $fieldMappingId;

    public string $platformName = '';

    public string $accountName = '';

    public string $internalFieldLabel = '';

    public string $externalFieldLabel = '';

    public bool $canMutate = false;

    public bool $externalChoicesResolvable = false;

    #[Url(as: 'search')]
    public ?string $search = '';

    #[Url(as: 'status')]
    public ?string $statusFilter = 'all';

    /** @var list<array<string, mixed>> */
    public array $displayRows = [];

    /** @var list<array<string, mixed>> */
    public array $staleRows = [];

    /** @var array<string, string> */
    public array $externalOptionChoices = [];

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
        return __('sync_option_mappings.title');
    }

    public function mount(string $account, string $configuration, string $mapping): void
    {
        $workspace = $this->resolveMappingWorkspace();
        $resolvedAccount = $this->resolveAccount($account, $workspace);

        $this->accountId = (string) $resolvedAccount->getKey();

        if (! $this->syncConfigurationBelongsToAccount($workspace, $resolvedAccount, $configuration)) {
            abort(404);
        }

        $this->configurationId = $configuration;

        if (! $this->fieldMappingBelongsToConfiguration($workspace, $configuration, $mapping)) {
            abort(404);
        }

        $this->fieldMappingId = $mapping;

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

    public function confirmMapping(string $internalOptionKey, string $externalOptionValue): void
    {
        if (! $this->canMutate || ! $this->externalChoicesResolvable) {
            abort(403);
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            app(FieldOptionMappingAuthorizationService::class)->confirm(
                $user,
                $this->resolveMappingWorkspace()->id,
                $this->accountId,
                $this->configurationId,
                $this->fieldMappingId,
                $internalOptionKey,
                $externalOptionValue,
            );

            Notification::make()
                ->title(__('sync_option_mappings.notifications.confirmed'))
                ->success()
                ->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (FieldOptionMappingConflictException|FieldMappingValidationException|FieldOptionMappingStaleMutationException $exception) {
            $this->notifyFailure($exception);
        }

        $this->refreshReadModel();
    }

    public function removeMapping(string $internalOptionKey, string $externalOptionValue): void
    {
        if (! $this->canMutate) {
            abort(403);
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            app(FieldOptionMappingAuthorizationService::class)->remove(
                $user,
                $this->resolveMappingWorkspace()->id,
                $this->accountId,
                $this->configurationId,
                $this->fieldMappingId,
                $internalOptionKey,
                $externalOptionValue,
            );

            Notification::make()
                ->title(__('sync_option_mappings.notifications.removed'))
                ->success()
                ->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (FieldOptionMappingConflictException|FieldMappingValidationException|FieldOptionMappingStaleMutationException $exception) {
            $this->notifyFailure($exception);
        }

        $this->refreshReadModel();
    }

    public function removeStaleCorrespondence(string $fieldOptionMappingId): void
    {
        if (! $this->canMutate) {
            abort(403);
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            app(FieldOptionMappingAuthorizationService::class)->removeStale(
                $user,
                $this->resolveMappingWorkspace()->id,
                $this->accountId,
                $this->configurationId,
                $this->fieldMappingId,
                $fieldOptionMappingId,
            );

            Notification::make()
                ->title(__('sync_option_mappings.notifications.removed'))
                ->success()
                ->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (FieldOptionMappingConflictException|FieldMappingValidationException|FieldOptionMappingStaleMutationException $exception) {
            $this->notifyFailure($exception);
        }

        $this->refreshReadModel();
    }

    public function changeMappingAction(): Action
    {
        return Action::make('changeMapping')
            ->modalHeading(__('sync_option_mappings.actions.change_heading'))
            ->schema([
                Select::make('external_option_value')
                    ->label($this->platformName)
                    ->options(fn (): array => $this->externalOptionChoices)
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                if (! $this->canMutate || ! $this->externalChoicesResolvable) {
                    abort(403);
                }

                $user = Auth::user();
                abort_unless($user instanceof User, 403);

                $internalOptionKey = $arguments['internalOptionKey'] ?? '';
                $currentExternalOptionValue = $arguments['externalOptionValue'] ?? '';
                $newExternalOptionValue = $data['external_option_value'] ?? '';

                try {
                    if ($currentExternalOptionValue === '') {
                        app(FieldOptionMappingAuthorizationService::class)->confirm(
                            $user,
                            $this->resolveMappingWorkspace()->id,
                            $this->accountId,
                            $this->configurationId,
                            $this->fieldMappingId,
                            $internalOptionKey,
                            $newExternalOptionValue,
                        );
                    } else {
                        app(FieldOptionMappingAuthorizationService::class)->replace(
                            $user,
                            $this->resolveMappingWorkspace()->id,
                            $this->accountId,
                            $this->configurationId,
                            $this->fieldMappingId,
                            $internalOptionKey,
                            $currentExternalOptionValue,
                            newExternalOptionValue: $newExternalOptionValue,
                        );
                    }

                    Notification::make()
                        ->title(__('sync_option_mappings.notifications.changed'))
                        ->success()
                        ->send();
                } catch (AuthorizationException) {
                    abort(403);
                } catch (FieldOptionMappingConflictException|FieldMappingValidationException|FieldOptionMappingStaleMutationException $exception) {
                    $this->notifyFailure($exception);
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
            $readModel = app(FieldOptionMappingAuthorizationService::class)->projectReadModel(
                $user,
                $workspace->id,
                $this->accountId,
                $this->configurationId,
                $this->fieldMappingId,
            );
        } catch (SyncConfigurationNotFoundException) {
            abort(404);
        }

        if (! $readModel->eligible) {
            abort(404);
        }

        $this->platformName = $readModel->platformName;
        $this->accountName = $readModel->accountName;
        $this->internalFieldLabel = $readModel->internalFieldLabel;
        $this->externalFieldLabel = $readModel->externalFieldLabel;
        $this->externalChoicesResolvable = $readModel->externalChoicesResolvable;
        $this->externalOptionChoices = $this->buildExternalOptionChoices($readModel);

        $this->refreshDisplayRows($readModel);
    }

    protected function refreshDisplayRows(?FieldOptionMappingReadModel $readModel = null): void
    {
        if ($readModel === null) {
            $user = Auth::user();
            abort_unless($user instanceof User, 403);

            $workspace = $this->resolveMappingWorkspace();

            try {
                $readModel = app(FieldOptionMappingAuthorizationService::class)->projectReadModel(
                    $user,
                    $workspace->id,
                    $this->accountId,
                    $this->configurationId,
                    $this->fieldMappingId,
                );
            } catch (SyncConfigurationNotFoundException) {
                abort(404);
            }
        }

        $rows = [];

        foreach ($readModel->normalRows as $row) {
            if (! $this->matchesStatusFilter($row)) {
                continue;
            }

            if (! $this->matchesSearch($row)) {
                continue;
            }

            $rows[] = $this->serializeNormalRow($row);
        }

        usort(
            $rows,
            fn (array $left, array $right): int => $this->compareRowsByAttention($left, $right),
        );

        $this->displayRows = $rows;
        $this->staleRows = array_map(
            fn ($row): array => [
                'field_option_mapping_id' => $row->fieldOptionMappingId,
                'external_label' => $row->externalLabel ?? __('sync_option_mappings.external_value_unavailable'),
                'status_label' => __('sync_option_mappings.status.stale_correspondence'),
                'internal_unavailable_label' => __('sync_option_mappings.stale.internal_unavailable'),
            ],
            $readModel->staleCorrespondenceRows,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function buildExternalOptionChoices(FieldOptionMappingReadModel $readModel): array
    {
        $options = [];

        foreach ($readModel->externalChoices as $choice) {
            $options[$choice->value] = $choice->presentationLabel();
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeNormalRow(FieldOptionMappingNormalRow $row): array
    {
        return [
            'internal_option_key' => $row->internalOptionKey,
            'internal_label' => $row->internalLabel,
            'external_label' => $row->externalLabel ?? __('sync_option_mappings.external_field_empty'),
            'semantic_state' => $row->semanticState,
            'existing_external_option_value' => $row->existingExternalOptionValue,
            'status_label' => __("sync_option_mappings.status.{$row->semanticState}"),
            'status_icon' => match ($row->semanticState) {
                FieldOptionMappingRowState::MAPPED => 'check',
                FieldOptionMappingRowState::EXTERNAL_VALUE_UNAVAILABLE => 'warning',
                default => null,
            },
        ];
    }

    protected function matchesStatusFilter(FieldOptionMappingNormalRow $row): bool
    {
        return match ($this->statusFilter) {
            'needs_attention' => in_array($row->semanticState, [
                FieldOptionMappingRowState::UNMAPPED,
                FieldOptionMappingRowState::EXTERNAL_VALUE_UNAVAILABLE,
            ], true),
            'mapped' => $row->semanticState === FieldOptionMappingRowState::MAPPED,
            'unmapped' => $row->semanticState === FieldOptionMappingRowState::UNMAPPED,
            default => true,
        };
    }

    protected function matchesSearch(FieldOptionMappingNormalRow $row): bool
    {
        $needle = mb_strtolower(trim((string) $this->search));

        if ($needle === '') {
            return true;
        }

        return str_contains(mb_strtolower($row->internalLabel), $needle)
            || str_contains(mb_strtolower((string) $row->externalLabel), $needle);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    protected function compareRowsByAttention(array $left, array $right): int
    {
        $priority = [
            FieldOptionMappingRowState::EXTERNAL_VALUE_UNAVAILABLE => 0,
            FieldOptionMappingRowState::UNMAPPED => 1,
            FieldOptionMappingRowState::MAPPED => 2,
        ];

        $leftPriority = $priority[$left['semantic_state']] ?? 99;
        $rightPriority = $priority[$right['semantic_state']] ?? 99;

        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        return strcmp((string) $left['internal_label'], (string) $right['internal_label']);
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

    protected function fieldMappingBelongsToConfiguration(
        Workspace $workspace,
        string $configurationId,
        string $fieldMappingId,
    ): bool {
        return FieldMapping::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('sync_configuration_id', $configurationId)
            ->whereKey($fieldMappingId)
            ->exists();
    }

    protected function notifyFailure(
        FieldOptionMappingConflictException|FieldMappingValidationException|FieldOptionMappingStaleMutationException $exception,
    ): void {
        $presenter = app(FieldOptionMappingSafeMessagePresenter::class);
        $presenter->report($exception);

        Notification::make()
            ->title(__('sync_option_mappings.notifications.failed'))
            ->body($presenter->present($exception))
            ->danger()
            ->send();
    }
}
