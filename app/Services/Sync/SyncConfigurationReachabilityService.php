<?php

namespace App\Services\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use App\Support\Sync\SyncExternalContext;

final class SyncConfigurationReachabilityService
{
    public function __construct(
        private readonly SyncConfigurationService $configurationService,
        private readonly ConnectorSyncSupportResolver $syncSupportResolver,
        private readonly SyncConfigurationLookupService $lookupService,
    ) {}

    public function ensureProductsExportConfiguration(ConnectorAccount $account): SyncConfiguration
    {
        if (! $this->syncSupportResolver->supportsConfiguration(
            $account,
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
        )) {
            throw new \RuntimeException('Products export is not supported for this connector account.');
        }

        $configuration = $this->lookupService->findProductsDefaultContext($account);

        if ($configuration === null) {
            return $this->configurationService->create($account, new CreateSyncConfigurationInput(
                dataDomain: SyncDataDomain::Products,
                externalContext: SyncExternalContext::default(),
                enabledOperations: [SyncSemanticOperation::Export],
            ));
        }

        if ($configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export)) {
            return $configuration->refresh();
        }

        return $this->configurationService->update(
            $account,
            $configuration->id,
            new UpdateSyncConfigurationInput(
                enabledOperations: [SyncSemanticOperation::Export],
            ),
        );
    }
}
