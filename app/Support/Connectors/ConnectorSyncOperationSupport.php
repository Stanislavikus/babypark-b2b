<?php

namespace App\Support\Connectors;

use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;

interface ConnectorSyncOperationSupport
{
    public function supports(
        SyncDataDomain $dataDomain,
        SyncSemanticOperation $operation,
        SyncRunMode $mode,
    ): bool;
}
