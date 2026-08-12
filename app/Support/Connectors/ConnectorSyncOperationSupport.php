<?php

namespace App\Support\Connectors;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;

interface ConnectorSyncOperationSupport
{
    public function supports(SyncDataDomain $dataDomain, SyncSemanticOperation $operation): bool;
}
