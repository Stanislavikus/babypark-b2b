<?php

namespace App\Filament\Resources\ConnectorAccountResource\Pages;

use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Filament\Resources\ConnectorAccountResource;
use App\Filament\Resources\ConnectorAccountResource\RelationManagers\ConnectionChecksRelationManager;
use App\Filament\Resources\ConnectorAccountResource\RelationManagers\DiscoveryRunsRelationManager;
use App\Models\ConnectorConnectionCheck;
use App\Models\ConnectorDiscoveryRun;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use App\Services\Connectors\ConnectorDiscoveryDispatchPort;
use App\Support\Connectors\ConnectorAccountMerchandiserPresentation;
use App\Support\Connectors\ConnectorAccountUiState;
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

    public function getSubheading(): string|Htmlable|null
    {
        if (ConnectorAccountMerchandiserPresentation::isMerchandiser(auth()->user())) {
            if (! config('connectors.discovery.manual_trigger_enabled')) {
                return null;
            }

            $disabledReason = app(ConnectorAccountUiState::class)
                ->manualDiscoveryActionState($this->record)['disabled_reason'];

            return filled($disabledReason) ? $disabledReason : null;
        }

        $disabledReason = app(ConnectorAccountUiState::class)
            ->manualCheckActionState($this->record)['disabled_reason'];

        return filled($disabledReason) ? $disabledReason : null;
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
        $managers = [
            DiscoveryRunsRelationManager::class,
        ];

        if (! ConnectorAccountMerchandiserPresentation::isMerchandiser(auth()->user())) {
            array_unshift($managers, ConnectionChecksRelationManager::class);
        }

        return $managers;
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        if (! ConnectorAccountMerchandiserPresentation::isMerchandiser(auth()->user())) {
            $actions[] = $this->makeRunConnectionCheckAction();
        }

        if (config('connectors.discovery.manual_trigger_enabled')) {
            $actions[] = $this->makeRunDiscoveryAction();
        }

        return $actions;
    }

    protected function resolveRecord(int|string $key): Model
    {
        $record = parent::resolveRecord($key);

        $record = ConnectorAccountMerchandiserPresentation::sanitizeRecord(
            $record,
            auth()->user(),
        );

        if (! ConnectorAccountMerchandiserPresentation::isMerchandiser(auth()->user())) {
            $record->makeHidden([
                'credentials',
                'settings',
                'base_url',
                'auth_profile',
            ]);
        }

        return ConnectorAccountResource::loadAccountPresentationRelations($record, auth()->user());
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
            ->icon('heroicon-o-magnifying-glass-circle')
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
                            ->title(__('connectors.ui.notifications.discovery_reused'))
                            ->send();
                    } elseif (in_array($run->status, [
                        ConnectorDiscoveryRunStatus::Queued,
                        ConnectorDiscoveryRunStatus::Running,
                    ], true)) {
                        Notification::make()
                            ->success()
                            ->title(__('connectors.ui.notifications.discovery_started'))
                            ->send();
                    } elseif ($run->status === ConnectorDiscoveryRunStatus::Failed) {
                        Notification::make()
                            ->danger()
                            ->title(__('connectors.ui.notifications.discovery_failed'))
                            ->body($presenter->present($run->user_message_key))
                            ->send();
                    }

                    $this->dispatch('refreshRelationManagers');
                } catch (ConnectorDiscoverySourceResolutionException) {
                    Notification::make()
                        ->danger()
                        ->title(__('connectors.ui.notifications.discovery_failed'))
                        ->body(__('connectors.errors.discovery_source_unavailable'))
                        ->send();
                } catch (ConnectorAccountDisabledException) {
                    Notification::make()
                        ->danger()
                        ->title(__('connectors.ui.notifications.discovery_failed'))
                        ->body(__('connectors.errors.account_disabled'))
                        ->send();
                } catch (AuthorizationException) {
                    if (! $this->record->is_enabled) {
                        Notification::make()
                            ->danger()
                            ->title(__('connectors.ui.notifications.discovery_failed'))
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
}
