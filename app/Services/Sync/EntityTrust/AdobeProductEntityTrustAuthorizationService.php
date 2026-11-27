<?php

namespace App\Services\Sync\EntityTrust;

use App\Models\ConnectorAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;

final class AdobeProductEntityTrustAuthorizationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
    ) {}

    public function canReviewOrConfirm(User $actor, Workspace $workspace): bool
    {
        return $this->hasDualPermissions($actor, $workspace);
    }

    public function assertReviewOrConfirm(User $actor, Workspace $workspace): void
    {
        if (! $this->canReviewOrConfirm($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    public function assertReviewOrConfirmUnderLockedWorkspace(User $actor, Workspace $workspace): void
    {
        $this->assertReviewOrConfirm($actor, $workspace);
    }

    public function resolveConnectorAccount(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
    ): ConnectorAccount {
        $this->assertReviewOrConfirm($actor, $workspace);

        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('id', $connectorAccountId)
            ->where('is_enabled', true)
            ->first();

        if ($account === null) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $account;
    }

    private function hasDualPermissions(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows($actor, $workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS)
            && $this->workspaceAuthorization->allows($actor, $workspace, WorkspacePermissions::RUN_SYNC_LIVE);
    }
}
