<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;

final class ConnectorSchemaSnapshotV2Hasher
{
    public const VERSION = 'v2';

    private const PREFIX = 'babypark.connector-schema-snapshot.v2';

    /** @param list<CanonicalSchemaFieldHash> $fields */
    public function hash(#[\SensitiveParameter] array $fields): string
    {
        if (! array_is_list($fields)) {
            throw ConnectorDiscoverySchemaValidationException::at(ConnectorDiscoverySchemaValidationReason::MalformedList, 'fields');
        }

        $seen = [];
        foreach ($fields as $index => $field) {
            if (! $field instanceof CanonicalSchemaFieldHash) {
                throw ConnectorDiscoverySchemaValidationException::at(ConnectorDiscoverySchemaValidationReason::InvalidType, "fields[{$index}]");
            }
            if (isset($seen[$field->externalFieldKey()])) {
                throw ConnectorDiscoverySchemaValidationException::at(ConnectorDiscoverySchemaValidationReason::DuplicateExternalFieldKey, 'fields');
            }
            $seen[$field->externalFieldKey()] = true;
        }

        usort($fields, static fn (CanonicalSchemaFieldHash $a, CanonicalSchemaFieldHash $b): int => strcmp($a->externalFieldKey(), $b->externalFieldKey()));
        $snapshot = new \stdClass;
        $snapshot->fields = array_map(static function (CanonicalSchemaFieldHash $field): \stdClass {
            $pair = new \stdClass;
            $pair->canonical_hash = $field->canonicalHash();
            $pair->external_field_key = $field->externalFieldKey();

            return $pair;
        }, $fields);

        try {
            $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ConnectorDiscoverySchemaValidationException::at(ConnectorDiscoverySchemaValidationReason::JsonEncodingFailed, '$');
        }

        return hash('sha256', self::PREFIX."\n".$json);
    }
}
