<?php

namespace App\Support\Sync\AdobeProductExportSetup;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Support\Connectors\AdobePaaS\AdobeProductExportPreviewCapability;
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

    public function isEligible(ConnectorAccount $account): bool
    {
        if (! $this->syncSupportResolver->supportsConfiguration(
            $account,
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
        )) {
            return false;
        }

        $definition = $this->profileRegistry->profileDefinition($account->auth_profile);

        return $definition->previewCapabilityClass === AdobeProductExportPreviewCapability::class;
    }
}
