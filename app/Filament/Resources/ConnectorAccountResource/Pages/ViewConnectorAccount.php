<?php

namespace App\Filament\Resources\ConnectorAccountResource\Pages;

use App\Enums\ConnectorComponentReadiness;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Filament\Pages\Sync\ManageAdobeProductsExportSetup;
use App\Filament\Resources\ConnectorAccountResource;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\ConnectorDiscoveryRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\AdobeSafeSyncComponentReadinessResolver;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use App\Services\Connectors\ConnectorDiscoveryDispatchPort;
use App\Services\Sync\AdobeProductExportSetupAuthorizationService;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncRequiredOperation;
use App\Support\Connectors\ConnectorAccountCapabilityPresentation;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\ConnectorAuthorization;
use App\Support\Connectors\ConnectorSafeMessagePresenter;
use App\Support\Connectors\Exceptions\ConnectorAccountDisabledException;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceResolutionException;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ViewConnectorAccount extends ViewRecord
{
    protected static string $resource = ConnectorAccountResource::class;

    public string $storeSetupState = 'NOT_CHECKED';

    public ?string $storeSetupBaselineMessage = null;

    public function getSubheading(): string|Htmlable|null
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }

        $workspace = $this->presentationWorkspace();
        $presentation = app(ConnectorAccountCapabilityPresentation::class);

        if ($presentation->showActiveConnectionCheck($user, $workspace)) {
            $disabledReason = app(ConnectorAccountUiState::class)
                ->manualCheckActionState($this->record)['disabled_reason'];

            return filled($disabledReason) ? $disabledReason : null;
        }

        if ($presentation->showDiscoveryExecution($user, $workspace)) {
            if (! config('connectors.discovery.manual_trigger_enabled')) {
                return null;
            }

            $disabledReason = app(ConnectorAccountUiState::class)
                ->manualDiscoveryActionState($this->record)['disabled_reason'];

            return filled($disabledReason) ? $disabledReason : null;
        }

        return null;
    }

    public function refreshConnectionState(): void
    {
        $this->record = $this->resolveRecord($this->record->getKey());
        $this->record = ConnectorAccountResource::loadAccountPresentationRelations(
            $this->record,
            auth()->user(),
        );
    }

    public function refreshDiscoveryState(): void
    {
        $this->refreshConnectionState();
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        $actions = [];
        $user = auth()->user();

        if (! $user instanceof User) {
            return $actions;
        }

        $presentation = app(ConnectorAccountCapabilityPresentation::class);
        $workspace = $this->presentationWorkspace();

        if ($presentation->showActiveConnectionCheck($user, $workspace)) {
            $actions[] = $this->makeRunConnectionCheckAction();
        }

        if ($presentation->showDiscoveryExecution($user, $workspace) && config('connectors.discovery.manual_trigger_enabled')) {
            $actions[] = $this->makeRunDiscoveryAction();
        }

        if ($this->shouldShowAdobeExportSetupLink($user, $workspace)) {
            $actions[] = $this->makeAdobeExportSetupAction();
        }

        return $actions;
    }

    protected function resolveRecord(int|string $key): Model
    {
        $record = parent::resolveRecord($key);
        $user = auth()->user();

        if ($user instanceof User) {
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
        }

        return ConnectorAccountResource::loadAccountPresentationRelations($record, $user);
    }

    public function shouldShowStoreSetupBlock(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $this->record instanceof ConnectorAccount) {
            return false;
        }

        $workspace = $this->presentationWorkspace();
        $presentation = app(ConnectorAccountCapabilityPresentation::class);

        if (! $presentation->showActiveConnectionCheck($user, $workspace)) {
            return false;
        }

        if ($this->record->auth_profile !== 'adobe_commerce_paas_oauth1_integration') {
            return false;
        }

        return Gate::forUser($user)->allows('runConnectionCheck', $this->record);
    }

    /**
     * @return array{enabled: bool, label: string, disabled_reason: ?string}
     */
    public function storeSetupActionState(): array
    {
        return app(ConnectorAccountUiState::class)->manualCheckActionState($this->record);
    }

    public function checkStoreSetup(): void
    {
        try {
            $actor = auth()->user();
            abort_unless($actor instanceof User, 403);

            $workspaceId = app(WorkspaceContext::class)->id();
            $account = ConnectorAccount::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->whereKey($this->record->getKey())
                ->firstOrFail();

            Gate::forUser($actor)->authorize('runConnectionCheck', $account);

            if (! app(ConnectorAccountUiState::class)->manualCheckActionState($account)['enabled']) {
                throw new AuthorizationException;
            }

            $result = app(AdobeSafeSyncComponentReadinessResolver::class)->resolve(
                $workspaceId,
                (string) $account->getKey(),
                AdobeSafeSyncRequiredOperation::SimpleProductWrite,
            );

            $readiness = $result->componentReadiness;

            if ($readiness !== null) {
                $this->storeSetupState = strtoupper($readiness->value);
                $this->storeSetupBaselineMessage = null;

                return;
            }

            $this->storeSetupState = 'BASELINE_FAILURE';
            $this->storeSetupBaselineMessage = app(ConnectorSafeMessagePresenter::class)->present(
                $result->connectionResult->messageKey(),
                $result->connectionResult->safeMessageParameters(),
            );
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title(__('connectors.ui.notifications.action_failed'))
                ->send();
        }
    }

    private function presentationWorkspace(): Workspace
    {
        if ($this->record instanceof ConnectorAccount) {
            return $this->record->workspace ?? Workspace::query()->findOrFail($this->record->workspace_id);
        }

        return app(WorkspaceContext::class)->current();
    }

    private function makeRunConnectionCheckAction(): Action
    {
        return Action::make('runConnectionCheck')
            ->label(fn (): string => app(ConnectorAccountUiState::class)
                ->manualCheckActionState($this->record)['label'])
            ->tooltip(fn (): ?string => app(ConnectorAccountUiState::class)
                ->manualCheckActionState($this->record)['disabled_reason'])
            ->extraAttributes(fn (): array => filled(app(ConnectorAccountUiState::class)
                ->manualCheckActionState($this->record)['disabled_reason'])
                ? ['title' => app(ConnectorAccountUiState::class)
                    ->manualCheckActionState($this->record)['disabled_reason']]
                : [])
            ->icon('heroicon-o-arrow-path')
            ->authorize('runConnectionCheck')
            ->disabled(fn (): bool => ! app(ConnectorAccountUiState::class)
                ->manualCheckActionState($this->record)['enabled'])
            ->action(function (): void {
                $actor = auth()->user();
                $workspaceId = app(WorkspaceContext::class)->id();
                $accountId = $this->record->getKey();

                try {
                    $checkId = app(ConnectorConnectionCheckDispatchService::class)->executeManual(
                        $actor,
                        $workspaceId,
                        $accountId,
                    );

                    $check = ConnectorConnectionCheck::query()->findOrFail($checkId);

                    $this->refreshConnectionState();

                    $presenter = app(ConnectorSafeMessagePresenter::class);

                    if (in_array($check->status, [
                        ConnectorConnectionCheckStatus::Queued,
                        ConnectorConnectionCheckStatus::Running,
                    ], true)) {
                        Notification::make()
                            ->success()
                            ->title(__('connectors.ui.notifications.check_started'))
                            ->send();
                    } elseif ($check->status === ConnectorConnectionCheckStatus::Succeeded) {
                        Notification::make()
                            ->success()
                            ->title(__('connectors.ui.notifications.check_completed'))
                            ->send();
                    } elseif ($check->status === ConnectorConnectionCheckStatus::Failed) {
                        Notification::make()
                            ->danger()
                            ->title(__('connectors.ui.notifications.check_failed'))
                            ->body($presenter->present(
                                $check->user_message_key,
                                $check->safe_message_parameters,
                            ))
                            ->send();
                    }

                    $this->dispatch('refreshRelationManagers');
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title(__('connectors.ui.notifications.action_failed'))
                        ->send();
                }
            });
    }

    private function makeRunDiscoveryAction(): Action
    {
        return Action::make('runDiscovery')
            ->label(fn (): string => app(ConnectorAccountUiState::class)
                ->manualDiscoveryActionState($this->record)['label'])
            ->tooltip(fn (): ?string => app(ConnectorAccountUiState::class)
                ->manualDiscoveryActionState($this->record)['disabled_reason'])
            ->extraAttributes(fn (): array => filled(app(ConnectorAccountUiState::class)
                ->manualDiscoveryActionState($this->record)['disabled_reason'])
                ? ['title' => app(ConnectorAccountUiState::class)
                    ->manualDiscoveryActionState($this->record)['disabled_reason']]
                : [])
            ->icon('heroicon-o-arrow-path')
            ->authorize('viewRunDiscovery')
            ->disabled(fn (): bool => ! app(ConnectorAccountUiState::class)
                ->manualDiscoveryActionState($this->record)['enabled'])
            ->action(function (): void {
                $actor = auth()->user();
                $workspaceId = app(WorkspaceContext::class)->id();
                $accountId = $this->record->getKey();

                try {
                    $this->record = $this->resolveRecord($accountId);

                    Gate::forUser($actor)->authorize('runDiscovery', $this->record);

                    $decision = app(ConnectorDiscoveryDispatchPort::class)->executeManual(
                        $actor,
                        $workspaceId,
                        $accountId,
                    );

                    $run = ConnectorDiscoveryRun::query()->findOrFail($decision->discoveryRunId);

                    $this->refreshDiscoveryState();

                    $presenter = app(ConnectorSafeMessagePresenter::class);

                    if (! $decision->shouldDispatch) {
                        Notification::make()
                            ->success()
                            ->title(__('connectors.ui.notifications.available_fields_refresh_reused'))
                            ->send();
                    } elseif (in_array($run->status, [
                        ConnectorDiscoveryRunStatus::Queued,
                        ConnectorDiscoveryRunStatus::Running,
                    ], true)) {
                        Notification::make()
                            ->success()
                            ->title(__('connectors.ui.notifications.available_fields_refresh_started'))
                            ->send();
                    } elseif ($run->status === ConnectorDiscoveryRunStatus::Failed) {
                        Notification::make()
                            ->danger()
                            ->title(__('connectors.ui.notifications.available_fields_refresh_failed'))
                            ->body($presenter->present($run->user_message_key))
                            ->send();
                    }

                    $this->dispatch('refreshRelationManagers');
                } catch (ConnectorDiscoverySourceResolutionException) {
                    Notification::make()
                        ->danger()
                        ->title(__('connectors.ui.notifications.available_fields_refresh_failed'))
                        ->body(__('connectors.errors.discovery_source_unavailable'))
                        ->send();
                } catch (ConnectorAccountDisabledException) {
                    Notification::make()
                        ->danger()
                        ->title(__('connectors.ui.notifications.available_fields_refresh_failed'))
                        ->body(__('connectors.errors.account_disabled'))
                        ->send();
                } catch (AuthorizationException) {
                    if (! $this->record->is_enabled) {
                        Notification::make()
                            ->danger()
                            ->title(__('connectors.ui.notifications.available_fields_refresh_failed'))
                            ->body(__('connectors.errors.account_disabled'))
                            ->send();
                    } else {
                        Notification::make()
                            ->danger()
                            ->title(__('connectors.ui.notifications.action_failed'))
                            ->send();
                    }
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title(__('connectors.ui.notifications.action_failed'))
                        ->send();
                }
            });
    }

    private function shouldShowAdobeExportSetupLink(User $user, Workspace $workspace): bool
    {
        if (! app(ConnectorAuthorization::class)->canSafeRead($user, $workspace)) {
            return false;
        }

        if (! $this->record instanceof ConnectorAccount) {
            return false;
        }

        return app(AdobeProductExportSetupAuthorizationService::class)
            ->isEligibleAdobeProductsExportSetupTarget($user, $workspace, $this->record->getKey());
    }

    private function makeAdobeExportSetupAction(): Action
    {
        return Action::make('openAdobeExportSetup')
            ->label(__('sync_data_setup.adobe_products_export.link'))
            ->url(ManageAdobeProductsExportSetup::getUrl([
                'account' => $this->record->getKey(),
            ]));
    }
}
