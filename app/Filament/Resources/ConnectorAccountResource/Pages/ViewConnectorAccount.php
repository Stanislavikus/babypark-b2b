<?php

namespace App\Filament\Resources\ConnectorAccountResource\Pages;

use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorErrorActionability;
use App\Filament\Pages\Sync\ManageAdobeProductsExportSetup;
use App\Filament\Resources\ConnectorAccountResource;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\AdobeSafeSyncComponentReadinessResolver;
use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use App\Services\Sync\AdobeProductExportSetupAuthorizationService;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncRequiredOperation;
use App\Support\Connectors\ConnectorAccountCapabilityPresentation;
use App\Support\Connectors\ConnectorAccountUiState;
use App\Support\Connectors\ConnectorAuthorization;
use App\Support\Connectors\ConnectorSafeMessagePresenter;
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

    public ?string $storeSetupModuleVersion = null;

    public ?string $storeSetupApplicationVersion = null;

    public ?string $storeSetupPhpVersion = null;

    /** @var array<string, int|string|array|null> */
    public array $storeSetupDiagnostics = [];

    public function getSubheading(): string|Htmlable|null
    {
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
            $actions[] = $this->makeRunConnectionCheckAction()
                ->visible(fn (): bool => ! ($this->shouldShowStoreSetupBlock() && $this->storeSetupState === 'BASELINE_CONNECTION_FAILED'));
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
        return $this->record instanceof ConnectorAccount
            && ConnectorAccountResource::shouldShowStoreSetupEntry($this->record);
    }

    /**
     * @return array{enabled: bool, label: string, disabled_reason: ?string}
     */
    public function storeSetupActionState(): array
    {
        return app(ConnectorAccountUiState::class)->manualCheckActionState($this->record);
    }

    public function checkStoreSetupAction(): Action
    {
        return Action::make('checkStoreSetup')
            ->label(fn (): string => $this->storeSetupState === 'NOT_CHECKED'
                ? __('connectors.ui.readiness.check')
                : __('connectors.ui.readiness.check_again'))
            ->color('gray')
            ->size('sm')
            ->authorize('runConnectionCheck')
            ->disabled(fn (): bool => ! $this->storeSetupActionState()['enabled'])
            ->tooltip(fn (): ?string => $this->storeSetupActionState()['disabled_reason'])
            ->action(function (): void {
                $this->executeStoreSetupCheck();
            });
    }

    private function executeStoreSetupCheck(): void
    {
        $this->storeSetupState = 'NOT_CHECKED';
        $this->storeSetupBaselineMessage = null;
        $this->storeSetupModuleVersion = null;
        $this->storeSetupApplicationVersion = null;
        $this->storeSetupPhpVersion = null;
        $this->storeSetupDiagnostics = [];

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
            $connectionResult = $result->connectionResult;
            $this->storeSetupDiagnostics = array_filter([
                'probe_family' => $connectionResult->probeFamily ?? ($result->baselineSucceeded ? 'safe_sync_handshake' : 'magento_products'),
                'http_status' => $connectionResult->httpStatus,
                'error_code' => $connectionResult->errorCode?->value,
                'expected_acl_resource' => $connectionResult->expectedAclResource,
                'observed_acl_resources' => $connectionResult->observedAclResources,
                'oauth_problem' => $connectionResult->recognizedOAuthProblem,
                'vendor_request_id' => $connectionResult->vendorRequestId,
                'response_shape' => $connectionResult->responseShape,
            ], static fn (mixed $value): bool => $value !== null && $value !== []);

            if ($readiness !== null) {
                $this->storeSetupState = strtoupper($readiness->value);
                $this->storeSetupBaselineMessage = null;
                $this->storeSetupModuleVersion = $result->moduleVersion;
                $this->storeSetupApplicationVersion = $result->applicationVersion;
                $this->storeSetupPhpVersion = $result->phpVersion;

                return;
            }

            $this->storeSetupBaselineMessage = app(ConnectorSafeMessagePresenter::class)->present(
                $result->connectionResult->messageKey(),
                $result->connectionResult->safeMessageParameters(),
            );

            if (! $result->baselineSucceeded) {
                if ($result->connectionResult->errorCode === ConnectorConnectionCheckErrorCode::AdobeInsufficientPermissions) {
                    $this->storeSetupState = 'BASELINE_PRODUCT_PERMISSION_REQUIRED';

                    return;
                }

                $this->storeSetupState = 'BASELINE_CONNECTION_FAILED';

                return;
            }

            if ($result->connectionResult->actionability() === ConnectorErrorActionability::AutomaticRetry) {
                $this->storeSetupState = 'READINESS_TEMPORARY_PROBLEM';

                return;
            }

            $this->storeSetupState = 'BASELINE_OK_READINESS_UNDETERMINED';
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
