<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;

final class AdobeProductExportRunMetadataPreparer
{
    public function __construct(
        private readonly AdobeProductExportMetadataReader $metadataReader,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function prepareMetadata(
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
}
