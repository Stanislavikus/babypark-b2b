<?php

namespace App\Support\Sync;

use App\Enums\SyncConfigurationOperationalState;

final class SyncConfigurationRevisionHasher
{
    private const PREFIX = 'babypark.sync-configuration-revision.v3';

    /**
     * @param  list<FieldMappingRevisionEntry|array{field_binding_id: string, external_field_key: string}>  $fieldMappings
     */
    public function hash(
        SyncOperationSet $enabledOperations,
        SyncConfigurationOperationalState $operationalState,
        array $fieldMappings = [],
    ): string {
        $payload = new \stdClass;
        $payload->enabled_operations = $enabledOperations->values();
        $payload->operational_state = $operationalState->value;
        $selection = new \stdClass;
        $selection->mode = 'all_products';
        $payload->selection = $selection;
        $payload->field_mappings = $this->canonicalizeFieldMappings($fieldMappings);

        $json = $this->encodeCanonicalJson($this->sortObjectKeysRecursively($payload));

        return hash('sha256', self::PREFIX."\n".$json);
    }

    /**
     * @param  list<FieldMappingRevisionEntry|array{field_binding_id: string, external_field_key: string}>  $fieldMappings
     * @return list<array{field_binding_id: string, external_field_key: string}>
     */
    private function canonicalizeFieldMappings(array $fieldMappings): array
    {
        $normalized = array_map(function (FieldMappingRevisionEntry|array $entry): array {
            if ($entry instanceof FieldMappingRevisionEntry) {
                return $entry->toRevisionArray();
            }

            return [
                'field_binding_id' => $entry['field_binding_id'],
                'external_field_key' => $entry['external_field_key'],
            ];
        }, $fieldMappings);

        usort(
            $normalized,
            static function (array $left, array $right): int {
                $bindingCompare = strcmp($left['field_binding_id'], $right['field_binding_id']);

                if ($bindingCompare !== 0) {
                    return $bindingCompare;
                }

                return strcmp($left['external_field_key'], $right['external_field_key']);
            },
        );

        return $normalized;
    }

    private function encodeCanonicalJson(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Sync configuration revision payload could not be encoded as canonical JSON.',
                previous: $exception,
            );
        }
    }

    private function sortObjectKeysRecursively(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $array = (array) $value;
            ksort($array, SORT_STRING);

            $result = new \stdClass;

            foreach ($array as $key => $nested) {
                $result->{$key} = $this->sortObjectKeysRecursively($nested);
            }

            return $result;
        }

        if (is_array($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->sortObjectKeysRecursively($item),
                $value,
            );
        }

        return $value;
    }
}
