<?php

namespace App\Filament\Resources\ConnectorAccountResource\Pages;

use Throwable;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Filament\Resources\ConnectorAccountResource;
use App\Filament\Resources\ConnectorAccountResource\RelationManagers\ConnectionChecksRelationManager;
use App\Models\ConnectorConnectionCheck;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use App\Support\Connectors\ConnectorAccountMerchandiserPresentation;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\ConnectorSafeMessagePresenter;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ViewConnectorAccount extends ViewRecord
{
    protected static string $resource = ConnectorAccountResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        if (ConnectorAccountMerchandiserPresentation::isMerchandiser(auth()->user())) {
            return null;
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

    protected function getAllRelationManagers(): array
    {
        if (ConnectorAccountMerchandiserPresentation::isMerchandiser(auth()->user())) {
            return [];
        }

        return [
            ConnectionChecksRelationManager::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        if (ConnectorAccountMerchandiserPresentation::isMerchandiser(auth()->user())) {
            return [];
        }

        return [
            Action::make('runConnectionCheck')
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
                }),
        ];
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
}
