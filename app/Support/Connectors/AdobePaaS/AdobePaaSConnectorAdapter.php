<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Support\Connectors\ConnectorAdapter;
use App\Support\Connectors\ConnectorSyncOperationSupport;

final class AdobePaaSConnectorAdapter implements ConnectorAdapter, ConnectorSyncOperationSupport
{
    public function supports(
        SyncDataDomain $dataDomain,
        SyncSemanticOperation $operation,
        SyncRunMode $mode,
    ): bool {
        if ($dataDomain !== SyncDataDomain::Products || $operation !== SyncSemanticOperation::Export) {
            return false;
        }

        return $mode === SyncRunMode::Preview;
    }
}
