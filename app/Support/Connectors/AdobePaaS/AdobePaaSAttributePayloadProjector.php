<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\CanonicalSchemaOption;
use App\Support\Connectors\CanonicalSchemaPayload;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;

final class AdobePaaSAttributePayloadProjector
{
    public const PROVIDER_METADATA_VERSION = 'adobe.product_attribute.v1';

    private const STRING_KEYS = [
        'frontend_input', 'scope', 'backend_type', 'source_model', 'backend_model',
    ];

    private const BOOL_KEYS = ['is_user_defined', 'is_visible'];

    public function projectStrict(#[\SensitiveParameter] \stdClass $raw): CanonicalSchemaPayload
    {
        $metadata = $this->projectMetadata($raw, strict: true);
        [$hasOptions, $options] = $this->projectOptions($raw, strict: true);

        $frontendInput = $metadata->frontend_input ?? null;
        if (in_array($frontendInput, ['select', 'multiselect'], true) && ! $hasOptions) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::MissingRequiredValue,
                'options',
            );
        }

        return CanonicalSchemaPayload::withProviderMetadata(
            self::PROVIDER_METADATA_VERSION,
            $metadata,
            $hasOptions ? $options : null,
        );
    }

    public function projectBestEffort(#[\SensitiveParameter] \stdClass $raw): CanonicalSchemaPayload
    {
        $metadata = $this->projectMetadata($raw, strict: false);
        [$hasOptions, $options] = $this->projectOptions($raw, strict: false);

        return CanonicalSchemaPayload::withProviderMetadata(
            self::PROVIDER_METADATA_VERSION,
            $metadata,
            $hasOptions ? $options : null,
        );
    }

    private function projectMetadata(#[\SensitiveParameter] \stdClass $raw, bool $strict): \stdClass
    {
        $metadata = new \stdClass;

        foreach (self::STRING_KEYS as $key) {
            $this->copyNullableString($raw, $metadata, $key, $strict);
        }
        foreach (self::BOOL_KEYS as $key) {
            $this->copyNullableBool($raw, $metadata, $key, $strict);
        }

        $this->copyStringList($raw, $metadata, 'apply_to', $strict);
        $this->copyCanonicalList($raw, $metadata, 'validation_rules', $strict);
        $this->copyCanonicalScalar($raw, $metadata, 'is_unique', $strict);
        $this->copyCanonicalScalar($raw, $metadata, 'default_value', $strict);

        return $metadata;
    }

    private function copyNullableString(\stdClass $raw, \stdClass $out, string $key, bool $strict): void
    {
        if (! property_exists($raw, $key)) {
            return;
        }
        $value = $raw->{$key};
        if ($value === null) {
            $out->{$key} = null;

            return;
        }
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->invalid($key, $value, $strict);

            return;
        }
        $out->{$key} = $value;
    }

    private function copyNullableBool(\stdClass $raw, \stdClass $out, string $key, bool $strict): void
    {
        if (! property_exists($raw, $key)) {
            return;
        }
        $value = $raw->{$key};
        if ($value === null || is_bool($value)) {
            $out->{$key} = $value;

            return;
        }
        $this->invalid($key, $value, $strict);
    }

    private function copyStringList(\stdClass $raw, \stdClass $out, string $key, bool $strict): void
    {
        if (! property_exists($raw, $key)) {
            return;
        }
        $value = $raw->{$key};
        if (! is_array($value) || ! array_is_list($value)) {
            $this->invalid($key, $value, $strict, ConnectorDiscoverySchemaValidationReason::MalformedList);

            return;
        }
        $normalized = [];
        foreach ($value as $index => $item) {
            if (! is_string($item) || ! mb_check_encoding($item, 'UTF-8')) {
                if ($strict) {
                    throw ConnectorDiscoverySchemaValidationException::at(
                        ConnectorDiscoverySchemaValidationReason::InvalidType,
                        "{$key}[{$index}]",
                    );
                }

                return;
            }
            $normalized[] = $item;
        }
        $out->{$key} = $normalized;
    }

    private function copyCanonicalList(\stdClass $raw, \stdClass $out, string $key, bool $strict): void
    {
        if (! property_exists($raw, $key)) {
            return;
        }
        $value = $raw->{$key};
        if (! is_array($value) || ! array_is_list($value)) {
            $this->invalid($key, $value, $strict, ConnectorDiscoverySchemaValidationReason::MalformedList);

            return;
        }
        try {
            $out->{$key} = array_map(
                fn (mixed $item, int $index): mixed => $this->canonicalValue($item, "{$key}[{$index}]"),
                $value,
                array_keys($value),
            );
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            if ($strict) {
                throw $exception;
            }
        }
    }

    private function copyCanonicalScalar(\stdClass $raw, \stdClass $out, string $key, bool $strict): void
    {
        if (! property_exists($raw, $key)) {
            return;
        }
        $value = $raw->{$key};
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $this->invalid($key, $value, $strict, ConnectorDiscoverySchemaValidationReason::InvalidUtf8);

                return;
            }
            $out->{$key} = $value;

            return;
        }
        $this->invalid($key, $value, $strict);
    }

    /** @return array{0: bool, 1: list<CanonicalSchemaOption>} */
    private function projectOptions(\stdClass $raw, bool $strict): array
    {
        if (! property_exists($raw, 'options')) {
            return [false, []];
        }

        if ($raw->options === null) {
            if ($strict) {
                throw ConnectorDiscoverySchemaValidationException::at(
                    ConnectorDiscoverySchemaValidationReason::MalformedList,
                    'options',
                );
            }

            return [false, []];
        }

        if (! is_array($raw->options) || ! array_is_list($raw->options)) {
            if ($strict) {
                throw ConnectorDiscoverySchemaValidationException::at(
                    ConnectorDiscoverySchemaValidationReason::MalformedList,
                    'options',
                );
            }

            return [false, []];
        }

        $options = [];
        try {
            foreach ($raw->options as $index => $row) {
                if (! $row instanceof \stdClass) {
                    throw ConnectorDiscoverySchemaValidationException::at(
                        ConnectorDiscoverySchemaValidationReason::MalformedObject,
                        "options[{$index}]",
                    );
                }
                if (! property_exists($row, 'value')) {
                    throw ConnectorDiscoverySchemaValidationException::at(
                        ConnectorDiscoverySchemaValidationReason::MissingRequiredValue,
                        "options[{$index}].value",
                    );
                }
                $options[] = CanonicalSchemaOption::fromRaw(
                    $row->value,
                    property_exists($row, 'label') ? $row->label : null,
                    "options[{$index}]",
                );
            }

            return [true, $options];
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            if ($strict) {
                throw $exception;
            }

            return [false, []];
        }
    }

    private function canonicalValue(mixed $value, string $path): mixed
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            if (! mb_check_encoding($value, 'UTF-8')) {
                throw ConnectorDiscoverySchemaValidationException::at(
                    ConnectorDiscoverySchemaValidationReason::InvalidUtf8,
                    $path,
                );
            }

            return $value;
        }
        if (is_array($value) && array_is_list($value)) {
            return array_map(
                fn (mixed $item, int $index): mixed => $this->canonicalValue($item, "{$path}[{$index}]"),
                $value,
                array_keys($value),
            );
        }
        if ($value instanceof \stdClass) {
            $out = new \stdClass;
            foreach ((array) $value as $key => $nested) {
                if (! mb_check_encoding((string) $key, 'UTF-8')) {
                    throw ConnectorDiscoverySchemaValidationException::at(
                        ConnectorDiscoverySchemaValidationReason::InvalidUtf8,
                        $path,
                    );
                }
                $out->{$key} = $this->canonicalValue($nested, $path);
            }

            return $out;
        }

        throw ConnectorDiscoverySchemaValidationException::at(
            ConnectorDiscoverySchemaValidationReason::UnsupportedCanonicalValue,
            $path,
        );
    }

    private function invalid(
        string $path,
        mixed $value,
        bool $strict,
        ConnectorDiscoverySchemaValidationReason $reason = ConnectorDiscoverySchemaValidationReason::InvalidType,
    ): void {
        if (! $strict) {
            return;
        }
        if ($reason === ConnectorDiscoverySchemaValidationReason::InvalidType && ! ($value === null || is_scalar($value))) {
            $reason = ConnectorDiscoverySchemaValidationReason::UnsupportedCanonicalValue;
        }
        throw ConnectorDiscoverySchemaValidationException::at($reason, $path);
    }
}
