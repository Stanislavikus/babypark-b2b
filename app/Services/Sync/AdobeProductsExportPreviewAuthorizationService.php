<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Connectors\Exceptions\ConnectorProfileNotFoundException;
use App\Support\Sync\AdobeProductExportSetup\AdobeProductExportSetupTargetEligibility;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;

final class AdobeProductsExportPreviewAuthorizationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly ConnectorAccountLayerBSetupProjectionQuery $projectionQuery,
        private readonly AdobeProductExportSetupTargetEligibility $targetEligibility,
    ) {}

    public function canAccess(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        );
    }

    public function canManageSetup(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        );
    }

    public function canViewMappings(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::VIEW_SYNC_MAPPINGS,
        );
    }

    public function canManageMappings(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::MANAGE_SYNC_MAPPINGS,
        );
    }

    public function isEligiblePreviewTarget(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
    ): bool {
        if (! $this->canAccess($actor, $workspace)) {
            return false;
        }

        $projection = $this->projectionQuery->resolveEligibility($workspace->id, $connectorAccountId);

        if ($projection === null) {
            return false;
        }

        return $this->targetEligibility->isPreviewEligible($projection);
    }

    public function isEligibleSetupTarget(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
    ): bool {
        if (! $this->canManageSetup($actor, $workspace)) {
            return false;
        }

        $projection = $this->projectionQuery->resolveEligibility($workspace->id, $connectorAccountId);

        if ($projection === null) {
            return false;
        }

        try {
            return $this->targetEligibility->isEligible($projection);
        } catch (ConnectorProfileNotFoundException) {
            return false;
        }
    }

    public function resolveConnectorAccount(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
    ): ConnectorAccount {
        if (! $this->isEligiblePreviewTarget($actor, $workspace, $connectorAccountId)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('id', $connectorAccountId)
            ->first();

        if ($account === null) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $account;
    }
}
