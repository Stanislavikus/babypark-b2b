<?php

namespace Tests\Support\Connectors;

use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Support\Connectors\ConnectorAdapter;
use App\Support\Connectors\ConnectorSyncOperationSupport;

final class TestSyncSupportConnectorAdapter implements ConnectorAdapter, ConnectorSyncOperationSupport
{
    /** @var list<array{0: SyncDataDomain, 1: SyncSemanticOperation, 2: SyncRunMode}> */
    private array $supportedTriples = [];

    /**
     * @param  list<array{0: SyncDataDomain, 1: SyncSemanticOperation, 2: SyncRunMode}>  $supportedTriples
     */
    public function __construct(array $supportedTriples = [])
    {
        $this->supportedTriples = $supportedTriples;
    }

    public function supports(
        SyncDataDomain $dataDomain,
        SyncSemanticOperation $operation,
        SyncRunMode $mode,
    ): bool {
        foreach ($this->supportedTriples as [$supportedDomain, $supportedOperation, $supportedMode]) {
            if ($supportedDomain === $dataDomain
                && $supportedOperation === $operation
                && $supportedMode === $mode
            ) {
                return true;
            }
        }

        return false;
    }
}
