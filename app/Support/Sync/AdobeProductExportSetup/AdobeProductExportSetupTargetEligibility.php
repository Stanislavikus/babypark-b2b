<?php

namespace App\Support\Sync\AdobeProductExportSetup;

use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Support\Connectors\AdobePaaS\AdobeProductExportPreviewCapability;
use App\Support\Connectors\ConnectorAccountLayerBSetupEligibilityProjection;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\ConnectorSyncSupportResolver;

/**
 * Determines whether a connector account is eligible for Stage 2A-1
 * Adobe Products Export merchant setup (before any Adobe HTTP).
 */
final class AdobeProductExportSetupTargetEligibility
{
    public function __construct(
        private readonly ConnectorSyncSupportResolver $syncSupportResolver,
        private readonly ConnectorProfileRegistry $profileRegistry,
    ) {}

    public function isEligible(ConnectorAccountLayerBSetupEligibilityProjection $projection): bool
    {
        if (! $this->hasAdobeProductsExportPreviewProfile($projection)) {
            return false;
        }

        return $this->syncSupportResolver->supportsConfiguration(
            $this->accountReferenceForSupport($projection),
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
        );
    }

    public function isPreviewEligible(ConnectorAccountLayerBSetupEligibilityProjection $projection): bool
    {
        if (! $this->hasAdobeProductsExportPreviewProfile($projection)) {
            return false;
        }

        return $this->syncSupportResolver->supports(
            $this->accountReferenceForSupport($projection),
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
            SyncRunMode::Preview,
        );
    }

    public function isLiveEligible(ConnectorAccountLayerBSetupEligibilityProjection $projection): bool
    {
        if (! $this->hasAdobeProductsExportPreviewProfile($projection)) {
            return false;
        }

        return $this->syncSupportResolver->supportsConfiguration(
            $this->accountReferenceForSupport($projection),
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
        );
    }

    private function hasAdobeProductsExportPreviewProfile(
        ConnectorAccountLayerBSetupEligibilityProjection $projection,
    ): bool {
        $definition = $this->profileRegistry->profileDefinition($projection->authProfile);

        return $definition->previewCapabilityClass === AdobeProductExportPreviewCapability::class;
    }

    private function accountReferenceForSupport(
        ConnectorAccountLayerBSetupEligibilityProjection $projection,
    ): ConnectorAccount {
        $account = new ConnectorAccount([
            'auth_profile' => $projection->authProfile,
        ]);
        $account->exists = true;

        return $account;
    }
}
