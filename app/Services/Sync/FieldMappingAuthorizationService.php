<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\FieldMappingReadModel\FieldMappingReadModel;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Outer authorization boundary for Field Mapping operations.
 * Inner mutation/projector services remain actor-agnostic.
 */
final class FieldMappingAuthorizationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly FieldMappingMutationService $mutationService,
        private readonly FieldMappingReadModelProjector $projector,
    ) {}

    public function projectReadModel(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
        string $syncConfigurationId,
    ): FieldMappingReadModel {
        [$workspace, $account] = $this->resolveConnectorAccount($actor, $workspaceId, $connectorAccountId);

        if (! $this->canRead($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $this->resolveSyncConfiguration($account, $syncConfigurationId);

        return $this->projector->project($account, $syncConfigurationId);
    }

    public function confirm(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
        string $syncConfigurationId,
        string $fieldBindingId,
        string $externalFieldKey,
    ): SyncConfiguration {
        [$workspace, $account] = $this->resolveConnectorAccount($actor, $workspaceId, $connectorAccountId);

        if (! $this->canMutate($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $this->resolveSyncConfiguration($account, $syncConfigurationId);

        return $this->mutationService->confirm(
            $account,
            $syncConfigurationId,
            $fieldBindingId,
            $externalFieldKey,
        );
    }

    public function replace(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
        string $syncConfigurationId,
        string $fieldBindingId,
        string $externalFieldKey,
        ?string $newFieldBindingId = null,
        ?string $newExternalFieldKey = null,
    ): SyncConfiguration {
        [$workspace, $account] = $this->resolveConnectorAccount($actor, $workspaceId, $connectorAccountId);

        if (! $this->canMutate($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $this->resolveSyncConfiguration($account, $syncConfigurationId);

        return $this->mutationService->replace(
            $account,
            $syncConfigurationId,
            $fieldBindingId,
            $externalFieldKey,
            $newFieldBindingId,
            $newExternalFieldKey,
        );
    }

    public function remove(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
        string $syncConfigurationId,
        string $fieldBindingId,
        string $externalFieldKey,
    ): SyncConfiguration {
        [$workspace, $account] = $this->resolveConnectorAccount($actor, $workspaceId, $connectorAccountId);

        if (! $this->canMutate($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $this->resolveSyncConfiguration($account, $syncConfigurationId);

        return $this->mutationService->remove(
            $account,
            $syncConfigurationId,
            $fieldBindingId,
            $externalFieldKey,
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
