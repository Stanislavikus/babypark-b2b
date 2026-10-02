<?php

namespace App\Services\Sync;

use App\Enums\SyncDataDomain;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Support\Sync\SyncExternalContext;

/**
 * Non-mutating SyncConfiguration identity resolution.
 * Performs zero create/update/touch/revision changes.
 */
final class SyncConfigurationLookupService
{
    public function find(
        ConnectorAccount $account,
        SyncDataDomain $dataDomain,
        SyncExternalContext $externalContext,
    ): ?SyncConfiguration {
        return SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('data_domain', $dataDomain)
            ->where('external_context_key', $externalContext->uniquenessKey())
            ->first();
    }

    public function findProductsDefaultContext(ConnectorAccount $account): ?SyncConfiguration
    {
        return $this->find(
            $account,
            SyncDataDomain::Products,
            SyncExternalContext::default(),
        );
    }
}
