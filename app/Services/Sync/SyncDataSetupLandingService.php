<?php

namespace App\Services\Sync;

use App\Filament\Pages\Sync\ManageAdobeProductsExportPreview;
use App\Filament\Pages\Sync\ManageAdobeProductsExportSetup;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Sync\AdobeProductExportSetup\AdobeProductExportSetupTargetEligibility;
use App\Support\Sync\AdobeProductExportSetup\SyncDataSetupLandingTargetSummary;
use App\Support\Sync\AdobeProductExportSetup\SyncDataSetupTargetKind;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;

final class SyncDataSetupLandingService
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly ConnectorAccountLayerBSetupProjectionQuery $projectionQuery,
        private readonly AdobeProductExportSetupTargetEligibility $targetEligibility,
    ) {}

    public function canAccessLanding(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ) || $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        ) || $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::RUN_SYNC_LIVE,
        );
    }

    public function canAccessSetup(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        );
    }

    public function canAccessPreview(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        );
    }

    public function canAccessLive(User $actor, Workspace $workspace): bool
    {
        return $this->workspaceAuthorization->allows(
            $actor,
            $workspace,
            WorkspacePermissions::RUN_SYNC_LIVE,
        );
    }

    /**
     * @return list<SyncDataSetupLandingTargetSummary>
     */
    public function listLandingTargets(User $actor, Workspace $workspace): array
    {
        if (! $this->canAccessLanding($actor, $workspace)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $canSetup = $this->canAccessSetup($actor, $workspace);
        $canPreview = $this->canAccessPreview($actor, $workspace);
        $canLive = $this->canAccessLive($actor, $workspace);
        $targets = [];

        foreach ($this->projectionQuery->listEligibilityForWorkspace($workspace->id) as $eligibilityProjection) {
            $setupVisible = $canSetup && $this->targetEligibility->isEligible($eligibilityProjection);
            $previewVisible = $canPreview && $this->targetEligibility->isPreviewEligible($eligibilityProjection);
            $liveVisible = $canLive && $this->targetEligibility->isLiveEligible($eligibilityProjection);

            if (! $setupVisible && ! $previewVisible && ! $liveVisible) {
                continue;
            }

            $targets[] = new SyncDataSetupLandingTargetSummary(
                accountId: $eligibilityProjection->id,
                platformName: $eligibilityProjection->platformName,
                accountName: $eligibilityProjection->accountName,
                setupUsable: $eligibilityProjection->isSetupUsable(),
                targetKind: SyncDataSetupTargetKind::AdobeProductsExport,
                setupActionVisible: $setupVisible,
                previewActionVisible: $previewVisible,
                liveActionVisible: $liveVisible,
                setupUrl: $setupVisible
                    ? ManageAdobeProductsExportSetup::getUrl(['account' => $eligibilityProjection->id])
                    : null,
                previewUrl: $previewVisible
                    ? ManageAdobeProductsExportPreview::getUrl(['account' => $eligibilityProjection->id])
                    : null,
                liveUrl: $liveVisible
                    ? ManageAdobeProductsExportPreview::getUrl(['account' => $eligibilityProjection->id])
                    : null,
            );
        }

        return $targets;
    }
}
