<?php

namespace App\Support\Sync\Preview;

interface SyncPreviewConnectorCapability
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
    public function planProduct(
        ProductExecutionAggregate $aggregate,
        array $snapshot,
        object $runContext,
    ): SyncPreviewPlanResult;
}
