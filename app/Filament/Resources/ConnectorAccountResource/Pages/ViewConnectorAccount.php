<?php

namespace App\Filament\Resources\ConnectorAccountResource\Pages;

use App\Enums\ConnectorConnectionCheckStatus;
use App\Filament\Resources\ConnectorAccountResource;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use App\Support\Connectors\ConnectorAccountCapabilityPresentation;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\ConnectorSafeMessagePresenter;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class ViewConnectorAccount extends ViewRecord
{
    protected static string $resource = ConnectorAccountResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    public function getTitle(): string|Htmlable
    {
        return __('connectors.ui.layer_a.account_title', [
            'platform' => $this->record->connectorDefinition?->code === 'adobe_commerce'
                ? __('connectors.ui.layer_a.magento_name')
                : ($this->record->connectorDefinition?->name ?? ''),
            'account' => $this->record->name,
        ]);
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
        return [];
    }

    protected function getHeaderActions(): array
    {
        $actions = [];
        $user = auth()->user();

        if (! $user instanceof User) {
            return $actions;
        }

        $workspace = $this->presentationWorkspace();
        $presentation = app(ConnectorAccountCapabilityPresentation::class);

        if ($presentation->canManage($user, $workspace)) {
            $actions[] = $this->makeRunConnectionCheckAction();
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
            ->color('gray')
            ->size('sm')
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
}
