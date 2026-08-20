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

        $canPreview = app(AdobeProductsExportPreviewAuthorizationService::class)
            ->canAccess($user, $workspace);
        $canLive = app(AdobeProductsExportLiveAuthorizationService::class)
            ->canAccess($user, $workspace);

        if (! $canPreview && ! $canLive) {
            abort(403);
        }
    }

    public function bootRequiresFreshWorkspaceAdobeProductsExportExecutionAccess(): void
    {
        $this->authorizeFreshAdobeProductsExportExecutionAccess();
    }
}
