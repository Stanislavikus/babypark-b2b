<?php

namespace App\Support\Sync\Preview;

use App\Models\SyncConfiguration;

interface SyncPreviewConnectorCapability
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function prepareRun(
        string $workspaceId,
        string $connectorAccountId,
        SyncConfiguration $configuration,
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
