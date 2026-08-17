<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Models\SyncConfiguration;
use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;
use App\Support\Sync\Preview\ProductExecutionAggregate;
use App\Support\Sync\Preview\SyncPreviewConfigurationReadinessPort;
use App\Support\Sync\Preview\SyncPreviewConnectorCapability;
use App\Support\Sync\Preview\SyncPreviewPlanResult;

final class AdobeProductExportPreviewCapability implements SyncPreviewConfigurationReadinessPort, SyncPreviewConnectorCapability
{
    public function __construct(
        private readonly AdobeProductExportMetadataReader $metadataReader,
        private readonly AdobeProductExportPreviewPlanner $planner,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function prepareRun(
        string $workspaceId,
        string $connectorAccountId,
        SyncConfiguration $configuration,
        array $snapshot,
    ): AdobeProductExportExecutionMetadata {
        $exportConfiguration = AdobeProductExportExecutionConfiguration::fromPayload(
            $configuration->connectorExecutionConfiguration()->payload(),
        );

        return $this->metadataReader->read(
            $workspaceId,
            $connectorAccountId,
            $exportConfiguration->attributeSetId,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function planProduct(
        ProductExecutionAggregate $aggregate,
        array $snapshot,
        object $runContext,
    ): SyncPreviewPlanResult {
        if (! $runContext instanceof AdobeProductExportExecutionMetadata) {
            throw new \InvalidArgumentException('Adobe product export preview requires AdobeProductExportExecutionMetadata run context.');
        }

        return $this->planner->plan($aggregate, $snapshot, $runContext);
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
