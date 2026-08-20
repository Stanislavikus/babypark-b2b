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

        if (! is_array($payload)) {
            return null;
        }

        $options = [];

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $optionId = $entry['id'] ?? null;
            $attributeId = $entry['attribute_id'] ?? null;
            $label = $entry['label'] ?? null;
            $position = $entry['position'] ?? null;
            $valuesRaw = $entry['values'] ?? null;

            if (! is_numeric($optionId) || ! is_numeric($attributeId) || ! is_string($label) || ! is_numeric($position)) {
                continue;
            }

            $values = [];

            if (is_array($valuesRaw)) {
                foreach ($valuesRaw as $valueEntry) {
                    if (! is_array($valueEntry)) {
                        continue;
                    }

                    $valueIndex = $valueEntry['value_index'] ?? null;
                    $valueLabel = $valueEntry['label'] ?? null;

                    if (! is_numeric($valueIndex) || ! is_string($valueLabel)) {
                        continue;
                    }

                    $values[] = [
                        'value_index' => (int) $valueIndex,
                        'label' => $valueLabel,
                    ];
                }
            }

            usort(
                $values,
                static fn (array $left, array $right): int => $left['value_index'] <=> $right['value_index'],
            );

            $options[] = new AdobeConfigurableRemoteOptionState(
                optionId: (int) $optionId,
                attributeId: (int) $attributeId,
                label: $label,
                position: (int) $position,
                values: $values,
            );
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

        if (! is_array($payload)) {
            return null;
        }

        $skus = [];

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $sku = $entry['sku'] ?? null;

            if (is_string($sku) && $sku !== '') {
                $skus[] = $sku;
            }
        }

        sort($skus);

        return $skus;
    }
}
