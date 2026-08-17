<?php

namespace App\Services\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Support\Connectors\AdobePaaS\AdobeProductExportMetadataReader;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\SyncExternalContext;
use Illuminate\Support\Facades\DB;

final class SyncConfigurationReachabilityService
{
    public function __construct(
        private readonly SyncConfigurationService $configurationService,
        private readonly ConnectorSyncSupportResolver $syncSupportResolver,
        private readonly AdobeProductExportMetadataReader $metadataReader,
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
            $configuration = $this->configurationService->create($account, new CreateSyncConfigurationInput(
                dataDomain: SyncDataDomain::Products,
                externalContext: SyncExternalContext::default(),
                enabledOperations: [SyncSemanticOperation::Export],
                operationalState: SyncConfigurationOperationalState::Enabled,
            ));
        } elseif (! $configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export)) {
            $enabledOperations = $configuration->enabledOperationSet()->operations();
            $enabledOperations[] = SyncSemanticOperation::Export;

            $configuration = $this->configurationService->update(
                $account,
                $configuration->id,
                new UpdateSyncConfigurationInput(
                    enabledOperations: $enabledOperations,
                    operationalState: SyncConfigurationOperationalState::Enabled,
                ),
            );
        }

        return $this->ensureAttributeSetSelection($account, $configuration);
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

    private function ensureAttributeSetSelection(
        ConnectorAccount $account,
        SyncConfiguration $configuration,
    ): SyncConfiguration {
        $current = $configuration->connectorExecutionConfiguration();

        if ($current->attributeSetId() !== null) {
            return $configuration->refresh();
        }

        $metadata = $this->metadataReader->read(
            $account->workspace_id,
            $account->id,
        );

        return DB::transaction(function () use ($account, $configuration, $metadata): SyncConfiguration {
            $locked = SyncConfiguration::withoutWorkspaceScope()
                ->where('id', $configuration->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedCurrent = $locked->connectorExecutionConfiguration();

            if ($lockedCurrent->attributeSetId() !== null) {
                return $locked;
            }

            return $this->configurationService->updateConnectorExecutionConfiguration(
                $account,
                $locked->id,
                ConnectorExecutionConfiguration::fromPayload([
                    'attribute_set_id' => $metadata->selectedAttributeSetId,
                ]),
            );
        });
    }
}
