<?php

namespace Tests\Support\Sync;

use App\Enums\SyncPreviewOutcome;
use App\Models\SyncConfiguration;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionConfiguration;
use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;
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
        SyncConfiguration $configuration,
        array $snapshot,
    ): object {
        return (object) [
            'attribute_set_id' => AdobeProductExportExecutionConfiguration::fromPayload(
                $configuration->connectorExecutionConfiguration()->payload(),
            )->attributeSetId,
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
        try {
            AdobeProductExportExecutionConfiguration::fromPayload(
                $configuration->connectorExecutionConfiguration()->payload(),
            );

            return true;
        } catch (ConnectorExecutionConfigurationValidationException) {
            return false;
        }
    }
}
