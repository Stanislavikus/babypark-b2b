<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;

final class CanonicalSchemaFieldHasher
{
    private const PREFIX = 'babypark.connector-schema-field.v1';

    public function hash(#[\SensitiveParameter] CanonicalSchemaField $field): string
    {
        $json = $this->encodeCanonicalJson($this->buildCanonicalFieldObject($field));

        return hash('sha256', self::PREFIX."\n".$json);
    }

    private function buildCanonicalFieldObject(CanonicalSchemaField $field): \stdClass
    {
        $object = new \stdClass;
        $object->external_field_key = $field->externalFieldKey();
        $object->external_label = $field->externalLabel();
        $object->external_scope = $field->externalScope();
        $object->is_localizable = $field->isLocalizable();
        $object->is_multi_value = $field->isMultiValue();
        $object->is_required = $field->isRequired();
        $object->normalized_data_type = $field->normalizedDataType();
        $object->normalized_payload = $field->normalizedPayload()->toCanonicalObject();
        $object->sort_order = $field->sortOrder();

        return $this->sortObjectKeysRecursively($object);
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
