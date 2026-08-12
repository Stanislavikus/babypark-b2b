<?php

namespace Tests\Support\Connectors;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Support\Connectors\ConnectorAdapter;
use App\Support\Connectors\ConnectorSyncOperationSupport;

final class TestSyncSupportConnectorAdapter implements ConnectorAdapter, ConnectorSyncOperationSupport
{
    /** @var list<array{0: SyncDataDomain, 1: SyncSemanticOperation}> */
    private array $supportedPairs = [];

    /**
     * @param  list<array{0: SyncDataDomain, 1: SyncSemanticOperation}>  $supportedPairs
     */
    public function __construct(array $supportedPairs = [])
    {
        $this->supportedPairs = $supportedPairs;
    }

    public function supports(SyncDataDomain $dataDomain, SyncSemanticOperation $operation): bool
    {
        foreach ($this->supportedPairs as [$supportedDomain, $supportedOperation]) {
            if ($supportedDomain === $dataDomain && $supportedOperation === $operation) {
                return true;
            }
        }

        return false;
    }
}
