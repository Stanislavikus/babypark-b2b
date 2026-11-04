<?php

namespace App\Services\Connectors;

use App\Jobs\Connectors\AdobeRemoteCatalogScanJob;
use App\Models\ConnectorAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

final class AdobeRemoteCatalogScanDispatchService
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
    ) {}

    public function dispatch(User $actor, Workspace $workspace, string $connectorAccountId): void
    {
        if (! $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        )) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($connectorAccountId)
            ->with('connectorDefinition')
            ->first();

        if (
            ! $account instanceof ConnectorAccount
            || $account->connectorDefinition?->code !== 'adobe_commerce'
            || $account->auth_profile !== 'adobe_commerce_paas_oauth1_integration'
            || ! $account->is_enabled
        ) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        AdobeRemoteCatalogScanJob::dispatch(
            (string) $workspace->id,
            (string) $account->id,
            (string) Str::uuid(),
        )
            ->afterCommit();
    }
}
