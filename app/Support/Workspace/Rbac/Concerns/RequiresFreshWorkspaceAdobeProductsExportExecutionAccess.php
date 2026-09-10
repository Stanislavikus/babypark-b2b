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
        $componentState = get_object_vars($this);
        $accountId = isset($componentState['accountId']) && is_string($componentState['accountId'])
            ? $componentState['accountId']
            : null;

        $previewAuth = app(AdobeProductsExportPreviewAuthorizationService::class);
        $liveAuth = app(AdobeProductsExportLiveAuthorizationService::class);

        // A. accountId === null — pre-mount / lifecycle. Broad preview,
        //    live, or setup permission may defer the exact target check
        //    to the page's own canAccess()/mount() guard which receives
        //    the route parameter directly. Do NOT use canAccess() /
        //    canAccessLive() alone to bypass target eligibility here.
        if ($accountId === null) {
            $broadAccess = $previewAuth->canAccess($user, $workspace)
                || $liveAuth->canAccessLive($user, $workspace)
                || $previewAuth->canManageSetup($user, $workspace)
                || $liveAuth->canManageSetup($user, $workspace);

            if (! $broadAccess) {
                abort(403);
            }

            return;
        }

        // B. accountId !== null — workspace-level permission is NOT
        //    sufficient authority for an arbitrary account on fresh
        //    Livewire requests either. Access must require at least one
        //    exact target-aware predicate, each of which already includes
        //    the corresponding permission check.
        $eligible = $previewAuth->isEligiblePreviewTarget($user, $workspace, $accountId)
            || $liveAuth->isEligibleLiveTarget($user, $workspace, $accountId)
            || $previewAuth->isEligibleSetupTarget($user, $workspace, $accountId);

        if (! $eligible) {
            abort(403);
        }
    }

    public function bootRequiresFreshWorkspaceAdobeProductsExportExecutionAccess(): void
    {
        $this->authorizeFreshAdobeProductsExportExecutionAccess();
    }
}
