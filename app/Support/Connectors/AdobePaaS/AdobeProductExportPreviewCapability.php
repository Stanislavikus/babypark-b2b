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
        array $snapshot,
    ): AdobeProductExportExecutionMetadata {
        $connectorConfig = $snapshot['connector_execution_configuration'] ?? null;

        if (! is_array($connectorConfig)) {
            throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                'Snapshot connector_execution_configuration must be a JSON object.',
            );
        }

        $exportConfiguration = AdobeProductExportExecutionConfiguration::fromPayload($connectorConfig);

        return $this->metadataReader->read(
            $workspaceId,
            $connectorAccountId,
            $exportConfiguration->attributeSetId,
            $this->extractRelevantAttributeCodes($snapshot),
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function extractRelevantAttributeCodes(array $snapshot): array
    {
        /** @var list<array<string, mixed>> $fieldMappings */
        $fieldMappings = $snapshot['field_mappings'] ?? [];
        $codes = [];

        foreach ($fieldMappings as $mapping) {
            $externalFieldKey = $mapping['external_field_key'] ?? null;

            if (is_string($externalFieldKey) && $externalFieldKey !== '') {
                $codes[] = $externalFieldKey;
            }
        }

        return array_values(array_unique($codes));
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
