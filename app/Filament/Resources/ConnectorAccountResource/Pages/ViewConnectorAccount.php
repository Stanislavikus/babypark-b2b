<?php

namespace App\Filament\Resources\ConnectorAccountResource\Pages;

use App\Enums\ConnectorConnectionCheckStatus;
use App\Filament\Resources\ConnectorAccountResource;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\AdobePaaSCredentialRotationService;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use App\Support\Connectors\ConnectorAccountCapabilityPresentation;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\ConnectorSafeMessagePresenter;
use App\Support\Connectors\Exceptions\AdobePaaSCredentialRotationConflictException;
use App\Support\Connectors\Exceptions\AdobePaaSCredentialRotationValidationException;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
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
            if ($this->supportsVerifiedCredentialRotation()) {
                $actions[] = $this->makeUpdateConnectionDetailsAction();
            }

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

    private function supportsVerifiedCredentialRotation(): bool
    {
        return $this->record instanceof ConnectorAccount
            && $this->record->connectorDefinition?->code === 'adobe_commerce'
            && $this->record->auth_profile === 'adobe_commerce_paas_oauth1_integration';
    }

    private function makeUpdateConnectionDetailsAction(): Action
    {
        return Action::make('updateConnectionDetails')
            ->label(__('connectors.ui.actions.update_connection_details'))
            ->icon('heroicon-o-key')
            ->color('gray')
            ->size('sm')
            ->authorize('replaceCredentials')
            ->modalHeading(__('connectors.ui.credentials.modal.heading'))
            ->modalDescription(__('connectors.ui.credentials.modal.description'))
            ->modalSubmitActionLabel(__('connectors.ui.credentials.modal.submit'))
            ->schema([
                TextInput::make('consumer_key')
                    ->label(__('connectors.ui.integrations.connect.fields.consumer_key'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('consumer_secret')
                    ->label(__('connectors.ui.integrations.connect.fields.consumer_secret'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->maxLength(255),
                TextInput::make('access_token')
                    ->label(__('connectors.ui.integrations.connect.fields.access_token'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('access_token_secret')
                    ->label(__('connectors.ui.integrations.connect.fields.access_token_secret'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                $workspace = $this->presentationWorkspace();

                if (! $actor instanceof User) {
                    abort(403);
                }

                try {
                    app(AdobePaaSCredentialRotationService::class)->replace(
                        $actor,
                        $workspace,
                        (string) $this->record->getKey(),
                        new OAuth1Credentials(
                            consumerKey: (string) $data['consumer_key'],
                            consumerSecret: (string) $data['consumer_secret'],
                            accessToken: (string) $data['access_token'],
                            accessTokenSecret: (string) $data['access_token_secret'],
                        ),
                    );

                    $this->refreshConnectionState();

                    Notification::make()
                        ->success()
                        ->title(__('connectors.ui.credentials.notifications.updated'))
                        ->send();

                    $this->dispatch('refreshRelationManagers');
                } catch (AdobePaaSCredentialRotationValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title(__('connectors.ui.credentials.notifications.not_updated'))
                        ->body(app(ConnectorSafeMessagePresenter::class)->present(
                            $exception->result->messageKey(),
                            $exception->result->safeMessageParameters(),
                        ))
                        ->send();
                } catch (AdobePaaSCredentialRotationConflictException) {
                    Notification::make()
                        ->warning()
                        ->title(__('connectors.ui.credentials.notifications.not_updated'))
                        ->body(__('connectors.ui.credentials.notifications.conflict'))
                        ->send();
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title(__('connectors.ui.notifications.action_failed'))
                        ->send();
                }
            });
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
