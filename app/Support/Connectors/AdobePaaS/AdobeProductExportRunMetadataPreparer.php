<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;

final class AdobeProductExportRunMetadataPreparer
{
    public function __construct(
        private readonly AdobeProductExportMetadataReader $metadataReader,
        private readonly AdobeProductExportPersistedMetadataReader $persistedMetadataReader,
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

        $classificationSetIds = $this->extractClassificationAttributeSetIds($snapshot);
        $relevantAttributeCodes = $this->extractRelevantAttributeCodes($snapshot);

        if (array_key_exists('adobe_product_classifications', $snapshot)) {
            return $this->persistedMetadataReader->readForAttributeSets(
                $workspaceId,
                $connectorAccountId,
                $classificationSetIds,
                $relevantAttributeCodes,
                $this->optionalLegacyAttributeSetId($connectorConfig),
            );
        }

        $legacyAttributeSetId = AdobeProductExportExecutionConfiguration::fromPayload($connectorConfig)->attributeSetId;

        return $this->metadataReader->read(
            $workspaceId,
            $connectorAccountId,
            $legacyAttributeSetId,
            $relevantAttributeCodes,
        );
    }

    /**
     * @param  array<string, mixed>  $connectorConfig
     */
    private function optionalLegacyAttributeSetId(array $connectorConfig): ?int
    {
        $value = $connectorConfig['attribute_set_id'] ?? null;

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<int>
     */
    private function extractClassificationAttributeSetIds(array $snapshot): array
    {
        $classifications = $snapshot['adobe_product_classifications'] ?? null;

        if (! is_array($classifications) || ! array_is_list($classifications)) {
            return [];
        }

        $ids = [];

        foreach ($classifications as $classification) {
            if (! is_array($classification)) {
                continue;
            }

            $value = $classification['provider_attribute_set_id'] ?? null;

            if (is_int($value) && $value > 0) {
                $ids[$value] = true;

                continue;
            }

            if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                $ids[(int) $value] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);

        return array_values($ids);
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
