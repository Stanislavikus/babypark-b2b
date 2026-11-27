<?php

namespace Tests\Support\Sync;

use App\Enums\SyncPreviewOutcome;
use App\Models\SyncConfiguration;
use App\Support\Sync\Preview\ProductExecutionAggregate;
use App\Support\Sync\Preview\SyncPreviewConfigurationReadinessPort;
use App\Support\Sync\Preview\SyncPreviewConnectorCapability;
use App\Support\Sync\Preview\SyncPreviewPlanResult;

final class TestSyncPreviewCapability implements SyncPreviewConfigurationReadinessPort, SyncPreviewConnectorCapability
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
    public function planProduct(
        ProductExecutionAggregate $aggregate,
        array $snapshot,
        object $runContext,
    ): SyncPreviewPlanResult {
        return new SyncPreviewPlanResult(
            outcome: SyncPreviewOutcome::Ready,
            findings: [],
        );
    }

    public function isReady(SyncConfiguration $configuration): bool
    {
        return true;
    }
}
