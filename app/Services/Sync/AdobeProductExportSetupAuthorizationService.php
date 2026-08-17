<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionConfiguration;
use App\Support\Connectors\ConnectorAccountLayerBSetupProjection;
use App\Support\Sync\AdobeProductExportSetup\AdobeProductExportSetupReadModel;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Outer authorization boundary for merchant Adobe Products Export setup.
 * Inner setup services remain actor-agnostic.
 */
final class AdobeProductExportSetupAuthorizationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly ConnectorAccountLayerBSetupProjectionQuery $projectionQuery,
        private readonly SyncConfigurationLookupService $lookupService,
        private readonly AdobeProductExportSetupService $setupService,
    ) {}

    public function canAccess(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        );
    }

    public function projectReadModel(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
    ): AdobeProductExportSetupReadModel {
        [$workspace, $projection] = $this->resolveProjection($actor, $workspaceId, $connectorAccountId);

        if (! $this->canAccess($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $account = $this->resolveConnectorAccount($workspaceId, $connectorAccountId);
        $attributeSets = $this->setupService->listAvailableAttributeSets($account);
        $configuration = $this->lookupService->findProductsDefaultContext($account);

        $configuredId = $this->resolveConfiguredAttributeSetId($configuration);
        $configuredName = $this->resolveAttributeSetName($attributeSets, $configuredId);
        $configuredStale = $configuredId !== null && $configuredName === null;
        $setupRequired = $configuredId === null || $configuredStale;
        $preselected = count($attributeSets) === 1
            ? $attributeSets[0]['attribute_set_id']
            : null;

        return new AdobeProductExportSetupReadModel(
            account: $projection,
            availableAttributeSets: $attributeSets,
            configuredAttributeSetId: $configuredId,
            configuredAttributeSetName: $configuredName,
            configuredSetStale: $configuredStale,
            setupRequired: $setupRequired,
            preselectedAttributeSetId: $preselected,
        );
    }

    public function configureAttributeSet(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
        int $attributeSetId,
    ): SyncConfiguration {
        [$workspace] = $this->resolveWorkspaceAccount($actor, $workspaceId, $connectorAccountId);

        if (! $this->canAccess($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $account = $this->resolveConnectorAccount($workspaceId, $connectorAccountId);

        $this->setupService->validateAttributeSetSelection($account, $attributeSetId);

        if (! $this->canAccess($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $this->setupService->persistValidatedAttributeSet($account, $attributeSetId);
    }

    /**
     * @return array{0: Workspace, 1: ConnectorAccountLayerBSetupProjection}
     */
    private function resolveProjection(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
    ): array {
        [$workspace] = $this->resolveWorkspaceAccount($actor, $workspaceId, $connectorAccountId);

        $projection = $this->projectionQuery->resolve($workspaceId, $connectorAccountId);

        if ($projection === null) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return [$workspace, $projection];
    }

    /**
     * @return array{0: Workspace}
     */
    private function resolveWorkspaceAccount(
        User $actor,
        string $workspaceId,
        string $connectorAccountId,
    ): array {
        $workspace = Workspace::query()->find($workspaceId);

        if ($workspace === null) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $projection = $this->projectionQuery->resolve($workspaceId, $connectorAccountId);

        if ($projection === null) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return [$workspace];
    }

    private function resolveConnectorAccount(string $workspaceId, string $connectorAccountId): ConnectorAccount
    {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectorAccountId)
            ->first();

        if ($account === null) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $account;
    }

    private function resolveConfiguredAttributeSetId(?SyncConfiguration $configuration): ?int
    {
        if ($configuration === null) {
            return null;
        }

        try {
            return AdobeProductExportExecutionConfiguration::fromPayload(
                $configuration->connectorExecutionConfiguration()->payload(),
            )->attributeSetId;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{attribute_set_id: int, attribute_set_name: string}>  $attributeSets
     */
    private function resolveAttributeSetName(array $attributeSets, ?int $attributeSetId): ?string
    {
        if ($attributeSetId === null) {
            return null;
        }

        foreach ($attributeSets as $attributeSet) {
            if (($attributeSet['attribute_set_id'] ?? null) === $attributeSetId) {
                return $attributeSet['attribute_set_name'] ?? null;
            }
        }

        return null;
    }
}
