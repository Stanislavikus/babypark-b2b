<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\FieldOptionMappingReadModel\FieldOptionMappingReadModel;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;

final class FieldOptionMappingAuthorizationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly FieldOptionMappingMutationService $mutationService,
        private readonly FieldOptionMappingReadModelProjector $projector,
    ) {}

    public function projectReadModel(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
        string $syncConfigurationId,
        string $fieldMappingId,
    ): FieldOptionMappingReadModel {
        [$workspace, $account] = $this->resolveConnectorAccount($actor, $workspaceId, $connectorAccountId);

        if (! $this->canRead($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $this->resolveSyncConfiguration($account, $syncConfigurationId);

        return $this->projector->project($account, $syncConfigurationId, $fieldMappingId);
    }

    public function confirm(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
        string $syncConfigurationId,
        string $fieldMappingId,
        string $internalOptionKey,
        string $externalOptionValue,
    ): SyncConfiguration {
        [$workspace, $account] = $this->resolveConnectorAccount($actor, $workspaceId, $connectorAccountId);

        if (! $this->canMutate($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $this->resolveSyncConfiguration($account, $syncConfigurationId);

        return $this->mutationService->confirm(
            $account,
            $syncConfigurationId,
            $fieldMappingId,
            $internalOptionKey,
            $externalOptionValue,
        );
    }

    public function replace(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
        string $syncConfigurationId,
        string $fieldMappingId,
        string $internalOptionKey,
        string $externalOptionValue,
        ?string $newExternalOptionValue = null,
    ): SyncConfiguration {
        [$workspace, $account] = $this->resolveConnectorAccount($actor, $workspaceId, $connectorAccountId);

        if (! $this->canMutate($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $this->resolveSyncConfiguration($account, $syncConfigurationId);

        return $this->mutationService->replace(
            $account,
            $syncConfigurationId,
            $fieldMappingId,
            $internalOptionKey,
            $externalOptionValue,
            newExternalOptionValue: $newExternalOptionValue,
        );
    }

    public function remove(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
        string $syncConfigurationId,
        string $fieldMappingId,
        string $internalOptionKey,
        string $externalOptionValue,
    ): SyncConfiguration {
        [$workspace, $account] = $this->resolveConnectorAccount($actor, $workspaceId, $connectorAccountId);

        if (! $this->canMutate($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $this->resolveSyncConfiguration($account, $syncConfigurationId);

        return $this->mutationService->remove(
            $account,
            $syncConfigurationId,
            $fieldMappingId,
            $internalOptionKey,
            $externalOptionValue,
        );
    }

    public function removeStale(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
        string $syncConfigurationId,
        string $fieldMappingId,
        string $fieldOptionMappingId,
    ): SyncConfiguration {
        [$workspace, $account] = $this->resolveConnectorAccount($actor, $workspaceId, $connectorAccountId);

        if (! $this->canMutate($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $this->resolveSyncConfiguration($account, $syncConfigurationId);

        return $this->mutationService->removeById(
            $account,
            $syncConfigurationId,
            $fieldMappingId,
            $fieldOptionMappingId,
        );
    }

    /**
     * @return array{0: Workspace, 1: ConnectorAccount}
     */
    private function resolveConnectorAccount(User $actor, string $workspaceId, string $connectorAccountId): array
    {
        $workspace = Workspace::query()->find($workspaceId);

        if ($workspace === null) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectorAccountId)
            ->first();

        if ($account === null) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return [$workspace, $account];
    }

    private function resolveSyncConfiguration(ConnectorAccount $account, string $syncConfigurationId): SyncConfiguration
    {
        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('id', $syncConfigurationId)
            ->first();

        if ($configuration === null) {
            throw SyncConfigurationNotFoundException::forId($syncConfigurationId);
        }

        return $configuration;
    }

    private function canRead(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows($actor, $workspace, WorkspacePermissions::VIEW_SYNC_MAPPINGS)
            || $this->workspaceAuthorization->allows($actor, $workspace, WorkspacePermissions::MANAGE_SYNC_MAPPINGS);
    }

    private function canMutate(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows($actor, $workspace, WorkspacePermissions::MANAGE_SYNC_MAPPINGS);
    }
}
