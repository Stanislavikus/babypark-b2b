<?php

namespace App\Support\Workspace\Rbac\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\AdobeProductsExportLiveAuthorizationService;
use App\Services\Sync\AdobeProductsExportPreviewAuthorizationService;
use App\Services\Sync\ConnectorAccountLayerBSetupProjectionQuery;
use App\Support\Workspace\WorkspaceContext;
use Illuminate\Support\Facades\Auth;

trait RequiresFreshWorkspaceAdobeProductsExportExecutionAccess
{
    protected function resolveAdobeProductsExportExecutionWorkspace(): Workspace
    {
        return app(WorkspaceContext::class)->current();
    }

    protected function authorizeFreshAdobeProductsExportExecutionAccess(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        $workspace = $this->resolveAdobeProductsExportExecutionWorkspace();
        $componentState = get_object_vars($this);
        $accountId = isset($componentState['accountId']) && is_string($componentState['accountId'])
            ? $componentState['accountId']
            : null;

        $canPreview = app(AdobeProductsExportPreviewAuthorizationService::class)
            ->canAccess($user, $workspace);
        $canLive = app(AdobeProductsExportLiveAuthorizationService::class)
            ->canAccessLive($user, $workspace);
        $canManageSetup = app(AdobeProductsExportPreviewAuthorizationService::class)
            ->canManageSetup($user, $workspace)
            || app(AdobeProductsExportLiveAuthorizationService::class)
                ->canManageSetup($user, $workspace);
        $targetExists = $accountId !== null
            && app(ConnectorAccountLayerBSetupProjectionQuery::class)
                ->resolve($workspace->id, $accountId) !== null;

        // On the initial page mount, Livewire boots this trait before mount()
        // assigns the locked accountId property. In that one setup-only path we
        // defer the exact target check to the page's own canAccess()/mount()
        // guard, which receives the route parameter directly.
        if (! $canPreview && ! $canLive && $canManageSetup && $accountId === null) {
            return;
        }

        if (! $canPreview && ! $canLive && ! ($canManageSetup && $targetExists)) {
            abort(403);
        }
    }

    public function bootRequiresFreshWorkspaceAdobeProductsExportExecutionAccess(): void
    {
        $this->authorizeFreshAdobeProductsExportExecutionAccess();
    }
}
