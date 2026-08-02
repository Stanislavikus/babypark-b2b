<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;

final class CanonicalSchemaSnapshotHasher
{
    private const PREFIX = 'babypark.connector-schema-snapshot.v1';

    /**
     * @param  list<CanonicalSchemaFieldHash>  $fields
     */
    public function hash(#[\SensitiveParameter] array $fields): string
    {
        if (! array_is_list($fields)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::MalformedList,
                'fields',
            );
        }

        foreach ($fields as $index => $field) {
            if (! $field instanceof CanonicalSchemaFieldHash) {
                throw ConnectorDiscoverySchemaValidationException::at(
                    ConnectorDiscoverySchemaValidationReason::InvalidType,
                    "fields[{$index}]",
                );
            }
        }

        $seen = [];

        foreach ($fields as $field) {
            if (isset($seen[$field->externalFieldKey()])) {
                throw ConnectorDiscoverySchemaValidationException::at(
                    ConnectorDiscoverySchemaValidationReason::DuplicateExternalFieldKey,
                    'fields',
                );
            }

            $seen[$field->externalFieldKey()] = true;
        }

        $sorted = $fields;
        usort(
            $sorted,
            static fn (CanonicalSchemaFieldHash $left, CanonicalSchemaFieldHash $right): int => strcmp(
                $left->externalFieldKey(),
                $right->externalFieldKey(),
            ),
        );

        $json = $this->encodeCanonicalJson($this->buildCanonicalSnapshotObject($sorted));

        return hash('sha256', self::PREFIX."\n".$json);
    }

    /**
     * @param  list<CanonicalSchemaFieldHash>  $fields
     */
    private function buildCanonicalSnapshotObject(array $fields): \stdClass
    {
        $snapshot = new \stdClass;
        $fieldObjects = [];

        foreach ($fields as $field) {
            $pair = new \stdClass;
            $pair->canonical_hash = $field->canonicalHash();
            $pair->external_field_key = $field->externalFieldKey();
            $fieldObjects[] = $this->sortObjectKeysRecursively($pair);
        }

        $snapshot->fields = $fieldObjects;

        return $this->sortObjectKeysRecursively($snapshot);
    }

    private function encodeCanonicalJson(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::JsonEncodingFailed,
                '$',
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
