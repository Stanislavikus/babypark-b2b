<?php

namespace App\Support\Connectors\AdobePaaS;

final class AdobeProductAttributeProviderRegistry
{
    /** @var array<string, array{owner: ?string, evidence_refs: list<string>}>|null */
    private ?array $attributes = null;

    /** @return array{owner: ?string, evidence_refs: list<string>}|null */
    public function find(string $externalFieldKey): ?array
    {
        return $this->attributes()[$externalFieldKey] ?? null;
    }

    /** @return array<string, array{owner: ?string, evidence_refs: list<string>}> */
    private function attributes(): array
    {
        if ($this->attributes !== null) {
            return $this->attributes;
        }

        $path = resource_path('connector-registry/adobe_commerce_product_attribute_registry.json');
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (($decoded['schema_version'] ?? null) !== 'adobe.product_attribute_registry.v1'
            || ($decoded['provider'] ?? null) !== 'adobe_commerce'
            || ($decoded['surface'] ?? null) !== 'product_attribute'
            || ! is_array($decoded['source_contracts'] ?? null)
            || ! array_is_list($decoded['source_contracts'])
            || $decoded['source_contracts'] === []
            || ! is_array($decoded['attributes'] ?? null)) {
            throw new \RuntimeException('Adobe product attribute provider registry is invalid.');
        }

        $attributes = [];
        foreach ($decoded['attributes'] as $key => $entry) {
            if (! is_string($key) || $key === '' || ! mb_check_encoding($key, 'UTF-8')
                || ! is_array($entry)
                || ! array_key_exists('owner', $entry)
                || ! ($entry['owner'] === null || (is_string($entry['owner']) && $entry['owner'] !== ''))
                || ! is_array($entry['evidence_refs'] ?? null)
                || ! array_is_list($entry['evidence_refs'])
                || $entry['evidence_refs'] === []
                || array_filter($entry['evidence_refs'], fn (mixed $ref): bool => ! is_string($ref) || $ref === '') !== []) {
                throw new \RuntimeException('Adobe product attribute provider registry entry is invalid.');
            }

            /** @var array{owner: ?string, evidence_refs: list<string>} $entry */
            $attributes[$key] = $entry;
        }

        return $this->attributes = $attributes;
    }
}
