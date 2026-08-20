<?php

namespace App\Support\Sync\Live;

use App\Support\Sync\Preview\ProductExecutionAggregate;

interface SyncLiveConnectorCapability
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function prepareRun(
        string $workspaceId,
        string $connectorAccountId,
        array $snapshot,
    ): object;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function executeProduct(
        ProductExecutionAggregate $aggregate,
        array $snapshot,
        object $runContext,
        SyncLiveConsequentialWriteGate $consequentialWriteGate,
    ): SyncLiveProductExecutionResult;
}
