<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\ConnectorAccountOperationLock;
use App\Support\Connectors\Exceptions\AdobePaaSCredentialRotationConflictException;
use App\Support\Connectors\Exceptions\AdobePaaSCredentialRotationValidationException;
use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;
use App\Support\Connectors\Exceptions\ConnectorAccountSettingsValidationException;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AdobePaaSCredentialRotationService
{
    private const AUTH_PROFILE = 'adobe_commerce_paas_oauth1_integration';

    public function __construct(
        private readonly AdobePaaSConnectionCheckCapability $connectionCheck,
    ) {}

    public function replace(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
        OAuth1Credentials $replacementCredentials,
    ): ConnectorAccountSettingsResult {
        $account = $this->findAccount($workspace->id, $connectorAccountId);
        $this->authorize($actor, $account);
        $this->assertSupportedAccount($account);

        $snapshot = $this->mutationSnapshot($account);
        $verificationStartedAt = now();
        $startedAtNs = hrtime(true);

        $result = $this->connectionCheck->checkConnection(new AdobePaaSRequestContext(
            baseUrl: (string) $account->base_url,
            storeCode: (string) $account->store_code,
            credentials: $replacementCredentials,
        ));

        $durationMs = (int) ceil((hrtime(true) - $startedAtNs) / 1_000_000);
        $verifiedAt = now();

        if (! $result->succeeded) {
            throw new AdobePaaSCredentialRotationValidationException($result);
        }

        try {
            return Cache::lock(ConnectorAccountOperationLock::cacheKey($connectorAccountId), 30)
                ->block(5, function () use (
                    $actor,
                    $workspace,
                    $connectorAccountId,
                    $replacementCredentials,
                    $snapshot,
                    $verificationStartedAt,
                    $verifiedAt,
                    $durationMs,
                ): ConnectorAccountSettingsResult {
                    return DB::transaction(function () use (
                        $actor,
                        $workspace,
                        $connectorAccountId,
                        $replacementCredentials,
                        $snapshot,
                        $verificationStartedAt,
                        $verifiedAt,
                        $durationMs,
                    ): ConnectorAccountSettingsResult {
                        Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

                        $lockedAccount = ConnectorAccount::withoutWorkspaceScope()
                            ->where('workspace_id', $workspace->id)
                            ->where('id', $connectorAccountId)
                            ->lockForUpdate()
                            ->first();

                        if ($lockedAccount === null) {
                            throw new ConnectorAccountNotFoundException('Connector account was not found.');
                        }

                        $this->authorize($actor, $lockedAccount);
                        $this->assertSupportedAccount($lockedAccount);

                        if ($this->mutationSnapshot($lockedAccount) !== $snapshot) {
                            throw new AdobePaaSCredentialRotationConflictException;
                        }

                        $lockedAccount->forceFill([
                            'credentials' => AdobePaaSCredentialMapper::toStorageArray($replacementCredentials),
                            'connection_status' => $lockedAccount->is_enabled ? ConnectorAccountConnectionStatus::Connected : ConnectorAccountConnectionStatus::Disabled,
                            'last_checked_at' => $verifiedAt,
                            'last_successful_check_at' => $verifiedAt,
                            'last_error_cause' => null,
                            'last_error_actionability' => null,
                            'last_error_message_key' => null,
                            'last_error_at' => null,
                        ])->save();

                        ConnectorConnectionCheck::withoutWorkspaceScope()->create([
                            'workspace_id' => $workspace->id,
                            'connector_account_id' => $lockedAccount->id,
                            'trigger' => ConnectorConnectionCheckTrigger::CredentialsReplacement,
                            'initiated_by_user_id' => $actor->getKey(),
                            'status' => ConnectorConnectionCheckStatus::Succeeded,
                            'execution_attempts' => 1,
                            'cause_category' => null,
                            'actionability' => null,
                            'error_code' => null,
                            'http_status' => 200,
                            'user_message_key' => null,
                            'safe_message_parameters' => [],
                            'technical_summary' => null,
                            'vendor_request_id' => null,
                            'started_at' => $verificationStartedAt,
                            'finished_at' => $verifiedAt,
                            'duration_ms' => $durationMs,
                        ]);

                        return $this->toResult($lockedAccount);
                    });
                });
        } catch (LockTimeoutException) {
            throw new AdobePaaSCredentialRotationConflictException;
        }
    }

    private function findAccount(string $workspaceId, string $connectorAccountId): ConnectorAccount
    {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectorAccountId)
            ->first();

        if ($account === null) {
            throw new ConnectorAccountNotFoundException('Connector account was not found.');
        }

        return $account;
    }

    private function authorize(User $actor, ConnectorAccount $account): void
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('updateSettings', $account);
        $gate->authorize('replaceCredentials', $account);
    }

    private function assertSupportedAccount(ConnectorAccount $account): void
    {
        if ($account->auth_profile !== self::AUTH_PROFILE || ! filled($account->base_url) || ! filled($account->store_code)) {
            throw new ConnectorAccountSettingsValidationException(
                'Connector account is not a complete Adobe Commerce OAuth1 integration target.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function mutationSnapshot(ConnectorAccount $account): array
    {
        return [
            'connector_definition_id' => (string) $account->connector_definition_id,
            'auth_profile' => (string) $account->auth_profile,
            'base_url' => (string) $account->base_url,
            'store_code' => (string) $account->store_code,
            'tenant_context' => $account->tenant_context,
            'settings' => $account->settings ?? [],
            'credentials' => $account->credentials ?? [],
            'is_enabled' => (bool) $account->is_enabled,
        ];
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
