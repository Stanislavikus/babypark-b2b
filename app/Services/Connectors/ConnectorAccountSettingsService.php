<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\ExternalRecordLink;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\ConnectorAccountMutationMode;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\CredentialMutation;
use App\Support\Connectors\Exceptions\ConnectorAccountNameConflict;
use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;
use App\Support\Connectors\Exceptions\ConnectorAccountSettingsValidationException;
use App\Support\Connectors\Exceptions\ConnectorAccountTargetFrozenException;
use App\Support\Connectors\Exceptions\ConnectorDefinitionNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ConnectorAccountSettingsService implements ConnectorAccountPersistencePort
{
    public function __construct(
        private readonly ConnectorProfileRegistry $profileRegistry,
        private readonly ConnectorAccountConstraintViolationClassifier $constraintViolationClassifier,
        private readonly AdobeConnectorAccountTargetSnapshotResolver $targetSnapshotResolver,
    ) {}

    public function create(
        User $actor,
        Workspace $workspace,
        CreateConnectorAccountInput $input,
    ): ConnectorAccountSettingsResult {
        Gate::forUser($actor)->authorize('create', [ConnectorAccount::class, $workspace]);

        $connectorDefinition = ConnectorDefinition::query()->find($input->connectorDefinitionId);

        if ($connectorDefinition === null) {
            throw new ConnectorDefinitionNotFoundException('Connector definition was not found.');
        }

        $this->profileRegistry->profileDefinition($input->authProfile);
        $schema = $this->profileRegistry->resolveAccountSchema($input->authProfile);
        $validatedName = $this->validateAccountName($input->name);
        $validatedState = $schema->validate(
            $input->settings,
            $input->credentialMutation,
            ConnectorAccountMutationMode::Create,
        );

        $credentials = $this->resolveCredentialsForCreate($input->credentialMutation);

        try {
            $account = DB::transaction(function () use (
                $workspace,
                $connectorDefinition,
                $input,
                $validatedName,
                $validatedState,
                $credentials,
            ): ConnectorAccount {
                return ConnectorAccount::withoutWorkspaceScope()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'connector_definition_id' => $connectorDefinition->id,
                    'name' => $validatedName,
                    'auth_profile' => $input->authProfile,
                    'base_url' => $validatedState->baseUrl,
                    'store_code' => $validatedState->storeCode,
                    'tenant_context' => $validatedState->tenantContext,
                    'is_enabled' => true,
                    'settings' => $validatedState->settings,
                    'credentials' => $credentials,
                    'connection_status' => ConnectorAccountConnectionStatus::Untested,
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->constraintViolationClassifier->isActiveNameUniquenessConflict($exception)) {
                throw new ConnectorAccountNameConflict(
                    'A connector account with this name already exists for the selected connector definition.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        return $this->toResult($account);
    }

    public function update(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
        UpdateConnectorAccountInput $input,
    ): ConnectorAccountSettingsResult {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('id', $connectorAccountId)
            ->first();

        if ($account === null) {
            throw new ConnectorAccountNotFoundException('Connector account was not found.');
        }

        $schema = $this->profileRegistry->resolveAccountSchema($account->auth_profile);
        $validatedState = $schema->validate(
            $input->settings,
            $input->credentialMutation,
            ConnectorAccountMutationMode::Update,
        );

        try {
            $account = DB::transaction(function () use (
                $actor,
                $workspace,
                $connectorAccountId,
                $validatedState,
                $input,
            ): ConnectorAccount {
                Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

                $lockedAccount = ConnectorAccount::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->where('id', $connectorAccountId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->authorizeUpdate($actor, $lockedAccount, $input->credentialMutation);

                if ($this->targetSnapshotResolver->wouldChangeTarget(
                    $lockedAccount,
                    $validatedState->baseUrl,
                    $validatedState->storeCode,
                ) && $this->hasTrustedMerchantConfirmedLinks($workspace->id, $lockedAccount->id)
                ) {
                    throw new ConnectorAccountTargetFrozenException;
                }

                $credentials = $this->resolveCredentialsForUpdate($lockedAccount, $input->credentialMutation);

                $lockedAccount->fill([
                    'base_url' => $validatedState->baseUrl,
                    'store_code' => $validatedState->storeCode,
                    'tenant_context' => $validatedState->tenantContext,
                    'settings' => $validatedState->settings,
                    'credentials' => $credentials,
                ]);
                $lockedAccount->save();

                return $lockedAccount;
            });
        } catch (QueryException $exception) {
            if ($this->constraintViolationClassifier->isActiveNameUniquenessConflict($exception)) {
                throw new ConnectorAccountNameConflict(
                    'A connector account with this name already exists for the selected connector definition.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        $account->refresh();

        return $this->toResult($account);
    }

    private function hasTrustedMerchantConfirmedLinks(string $workspaceId, string $connectorAccountId): bool
    {
        return ExternalRecordLink::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('trust_origin', ExternalRecordLinkTrustOrigin::MerchantConfirmed->value)
            ->whereNotNull('external_record_discriminator')
            ->where('external_record_discriminator', '!=', '')
            ->whereNotNull('established_by_workspace_user_id')
            ->whereNotNull('established_at')
            ->exists();
    }

    private function authorizeUpdate(User $actor, ConnectorAccount $account, CredentialMutation $credentialMutation): void
    {
        $gate = Gate::forUser($actor);

        $gate->authorize('updateSettings', $account);

        if ($credentialMutation->isReplace()) {
            $gate->authorize('replaceCredentials', $account);
        }

        if ($credentialMutation->isRemove()) {
            $gate->authorize('removeCredentials', $account);
        }
    }

    private function validateAccountName(string $name): string
    {
        if ($name === '') {
            throw new ConnectorAccountSettingsValidationException('Connector account name must not be empty.');
        }

        if (strlen($name) > 255) {
            throw new ConnectorAccountSettingsValidationException(
                'Connector account name must not exceed 255 characters.',
            );
        }

        return $name;
    }

    /**
     * @return array<string, string>
     */
    private function resolveCredentialsForCreate(CredentialMutation $credentialMutation): array
    {
        if ($credentialMutation->isReplace()) {
            return AdobePaaSCredentialMapper::toStorageArray($credentialMutation->credentials);
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function resolveCredentialsForUpdate(ConnectorAccount $account, CredentialMutation $credentialMutation): array
    {
        if ($credentialMutation->isKeep()) {
            return $account->credentials ?? [];
        }

        if ($credentialMutation->isReplace()) {
            return AdobePaaSCredentialMapper::toStorageArray($credentialMutation->credentials);
        }

        return [];
    }

    private function toResult(ConnectorAccount $account): ConnectorAccountSettingsResult
    {
        return new ConnectorAccountSettingsResult(
            id: $account->id,
            connectorDefinitionId: $account->connector_definition_id,
            authProfile: $account->auth_profile,
            baseUrl: (string) $account->base_url,
            storeCode: (string) $account->store_code,
            tenantContext: $account->tenant_context,
            settings: $account->settings ?? [],
            isEnabled: $account->is_enabled,
            hasCredentials: AdobePaaSCredentialMapper::hasCompleteSet($account->credentials),
        );
    }
}
