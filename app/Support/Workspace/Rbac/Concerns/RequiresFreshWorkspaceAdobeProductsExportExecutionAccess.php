<?php

namespace App\Support\Workspace\Rbac\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\AdobeProductsExportLiveAuthorizationService;
use App\Services\Sync\AdobeProductsExportPreviewAuthorizationService;
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

        $previewAuth = app(AdobeProductsExportPreviewAuthorizationService::class);
        $liveAuth = app(AdobeProductsExportLiveAuthorizationService::class);

        // A user with manage_sync_configurations can manage setup. The page
        // itself is the management surface; the dual-permission enforcement
        // for the actual review/confirm lives in the orchestrator.
        $canPreview = $previewAuth->canAccess($user, $workspace);
        $canLive = $liveAuth->canAccessLive($user, $workspace);
        $canManageSetup = $previewAuth->canManageSetup($user, $workspace);

        if (! $canPreview && ! $canLive && ! $canManageSetup) {
            abort(403);
        }
    }

    public function bootRequiresFreshWorkspaceAdobeProductsExportExecutionAccess(): void
    {
        $this->authorizeFreshAdobeProductsExportExecutionAccess();
    }
}
