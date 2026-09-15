<?php

namespace Tests\Support\Sync;

use App\Enums\SyncLiveOutcome;
use App\Support\Sync\Live\SyncLiveConnectorCapability;
use App\Support\Sync\Live\SyncLiveConsequentialWriteGate;
use App\Support\Sync\Live\SyncLiveProductExecutionResult;
use App\Support\Sync\Preview\ProductExecutionAggregate;

final class TestSyncLiveCapability implements SyncLiveConnectorCapability
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function prepareRun(
        string $workspaceId,
        string $connectorAccountId,
        array $snapshot,
    ): object {
        return (object) [
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'snapshot_attribute_set_id' => $snapshot['connector_execution_configuration']['attribute_set_id'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function executeProduct(
        ProductExecutionAggregate $aggregate,
        array $snapshot,
        object $runContext,
        SyncLiveConsequentialWriteGate $consequentialWriteGate,
    ): SyncLiveProductExecutionResult {
        return new SyncLiveProductExecutionResult(
            outcome: SyncLiveOutcome::Synchronized,
            findings: [],
        );
    }
}
