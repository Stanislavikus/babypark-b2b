<?php

namespace App\Support\Connectors;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;

final class ConnectorSyncSupportResolver
{
    public function __construct(
        private readonly ConnectorProfileRegistry $profileRegistry,
    ) {}

    public function supports(
        ConnectorAccount $account,
        SyncDataDomain $dataDomain,
        SyncSemanticOperation $operation,
    ): bool {
        $adapter = $this->profileRegistry->resolveAdapter($account->auth_profile);

        if (! $adapter instanceof ConnectorSyncOperationSupport) {
            return false;
        }

        return $adapter->supports($dataDomain, $operation);
    }
}
