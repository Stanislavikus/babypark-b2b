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

        $configuration = $this->findExistingProductsExportConfiguration($account);

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

        $enabledOperations = $configuration->enabledOperationSet()->operations();
        $enabledOperations[] = SyncSemanticOperation::Export;

        return $this->configurationService->update(
            $account,
            $configuration->id,
            new UpdateSyncConfigurationInput(
                enabledOperations: $enabledOperations,
            ),
        );
    }

    private function findExistingProductsExportConfiguration(ConnectorAccount $account): ?SyncConfiguration
    {
        return SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('data_domain', SyncDataDomain::Products)
            ->where('external_context_key', SyncExternalContext::default()->uniquenessKey())
            ->first();
    }
}
