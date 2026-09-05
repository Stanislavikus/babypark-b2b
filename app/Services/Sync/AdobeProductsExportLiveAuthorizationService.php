<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Sync\AdobeProductExportSetup\AdobeProductExportSetupTargetEligibility;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;

final class AdobeProductsExportLiveAuthorizationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly ConnectorAccountLayerBSetupProjectionQuery $projectionQuery,
        private readonly AdobeProductExportSetupTargetEligibility $targetEligibility,
    ) {}

    public function canAccessLive(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::RUN_SYNC_LIVE,
        );
    }

    /** @deprecated Use canAccessLive() */
    public function canAccess(User $actor, Workspace $workspace): bool
    {
        return $this->canAccessLive($actor, $workspace);
    }

    public function canManageSetup(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        );
    }

    public function isEligibleLiveTarget(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
    ): bool {
        if (! $this->canAccessLive($actor, $workspace)) {
            return false;
        }

        $projection = $this->projectionQuery->resolveEligibility($workspace->id, $connectorAccountId);

        if ($projection === null) {
            return false;
        }

        return $this->targetEligibility->isLiveEligible($projection);
    }

    public function resolveConnectorAccount(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
    ): ConnectorAccount {
        if (! $this->isEligibleLiveTarget($actor, $workspace, $connectorAccountId)) {
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
