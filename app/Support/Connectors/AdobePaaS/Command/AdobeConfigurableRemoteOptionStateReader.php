<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\Transport\ConnectorHttpResult;

final class AdobeConfigurableRemoteOptionStateReader
{
    /**
     * @return list<AdobeConfigurableRemoteOptionState>|null
     */
    public function read(?ConnectorHttpResult $httpResult): ?array
    {
        if ($httpResult === null || $httpResult->statusCode !== 200) {
            return null;
        }

        $payload = json_decode($httpResult->body, true);

        if (! is_array($payload) || ! array_is_list($payload)) {
            return null;
        }

        $options = [];

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                return null;
            }

            $normalized = $this->normalizeOptionEntry($entry);

            if ($normalized === null) {
                return null;
            }

            $options[] = $normalized;
        }

        return $options;
    }

    /**
     * @return list<string>|null
     */
    public function readChildSkus(?ConnectorHttpResult $httpResult): ?array
    {
        if ($httpResult === null || $httpResult->statusCode !== 200) {
            return null;
        }

        $payload = json_decode($httpResult->body, true);

        if (! is_array($payload) || ! array_is_list($payload)) {
            return null;
        }

        $skus = [];

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                return null;
            }

            $sku = $entry['sku'] ?? null;

            if (! is_string($sku) || $sku === '') {
                return null;
            }

            $skus[] = $sku;
        }

        sort($skus);

        return $skus;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function normalizeOptionEntry(array $entry): ?AdobeConfigurableRemoteOptionState
    {
        $optionId = AdobeConfigurableValueIndexNormalizer::normalize($entry['id'] ?? null);

        if ($optionId === null || $optionId === 0) {
            return null;
        }

        $attributeId = AdobeConfigurableValueIndexNormalizer::normalize($entry['attribute_id'] ?? null);

        if ($attributeId === null || $attributeId === 0) {
            return null;
        }

        $label = $entry['label'] ?? null;

        if (! is_string($label) || $label === '') {
            return null;
        }

        $position = AdobeConfigurableValueIndexNormalizer::normalize($entry['position'] ?? null);

        if ($position === null) {
            return null;
        }

        $valuesRaw = $entry['values'] ?? null;

        if (! is_array($valuesRaw)) {
            return null;
        }

        $values = [];

        foreach ($valuesRaw as $valueEntry) {
            if (! is_array($valueEntry)) {
                return null;
            }

            $valueIndex = AdobeConfigurableValueIndexNormalizer::normalize($valueEntry['value_index'] ?? null);

            if ($valueIndex === null) {
                return null;
            }

            $values[] = $valueIndex;
        }

        sort($values);

        return new AdobeConfigurableRemoteOptionState(
            optionId: $optionId,
            attributeId: $attributeId,
            label: $label,
            position: $position,
            values: $values,
        );
    }
}
