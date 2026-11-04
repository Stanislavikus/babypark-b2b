<?php

namespace App\Support\CanonicalRegistry;

final class CanonicalMappingSnapshotKeyResolver
{
    /** @param array<string, string> $mappingRow */
    public function resolve(string $channel, array $mappingRow): ?string
    {
        $external = $mappingRow['external_field'] ?? '';
        if ($external === '') {
            return null;
        }

        if ($channel !== 'adobe_commerce') {
            return $external;
        }

        if (preg_match('/^custom_attributes\\[attribute_code=([^\\]]+)\\]\\.value$/', $external, $matches) === 1) {
            return $matches[1];
        }

        if (($mappingRow['transformation'] ?? '') === 'category_relation_to_adobe_category_ids') {
            return 'category_ids';
        }

        return $external;
    }
}
