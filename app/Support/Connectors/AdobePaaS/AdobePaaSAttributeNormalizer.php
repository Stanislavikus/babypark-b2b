<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\CanonicalSchemaField;
use App\Support\Connectors\CanonicalSchemaOption;
use App\Support\Connectors\CanonicalSchemaPayload;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;

final class AdobePaaSAttributeNormalizer
{
    public function normalize(#[\SensitiveParameter] mixed $raw): CanonicalSchemaField
    {
        if (! $raw instanceof \stdClass) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::MalformedObject,
                '$',
            );
        }

        $attributeCode = $this->requirePresentNonEmptyString($raw, 'attribute_code');
        $frontendInput = $this->requirePresentNonEmptyString($raw, 'frontend_input');
        $normalizedDataType = $this->mapFrontendInput($frontendInput);
        $externalLabel = $this->readNullableString($raw, 'default_frontend_label');
        $isRequired = $this->readNullableBool($raw, 'is_required');
        $scope = $this->requirePresentString($raw, 'scope');
        $externalScope = $this->mapScope($scope);
        $isLocalizable = $externalScope === 'store';
        $isMultiValue = in_array($frontendInput, ['multiselect', 'gallery'], true);
        $sortOrder = $this->readPosition($raw);
        $normalizedPayload = $this->buildNormalizedPayload($raw, $frontendInput);

        return CanonicalSchemaField::create(
            $attributeCode,
            $externalLabel,
            $normalizedDataType,
            $isRequired,
            $isMultiValue,
            $isLocalizable,
            $externalScope,
            $normalizedPayload,
            $sortOrder,
        );
    }

    private function requirePresentNonEmptyString(
        #[\SensitiveParameter] \stdClass $raw,
        string $property,
    ): string {
        if (! property_exists($raw, $property)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::MissingRequiredValue,
                $property,
            );
        }

        $value = $raw->{$property};

        if (! is_string($value)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                $this->classifyViolation($value),
                $property,
            );
        }

        if ($value === '') {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::EmptyRequiredString,
                $property,
            );
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::InvalidUtf8,
                $property,
            );
        }

        return $value;
    }

    private function requirePresentString(
        #[\SensitiveParameter] \stdClass $raw,
        string $property,
    ): string {
        if (! property_exists($raw, $property)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::MissingRequiredValue,
                $property,
            );
        }

        $value = $raw->{$property};

        if (! is_string($value)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                $this->classifyViolation($value),
                $property,
            );
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::InvalidUtf8,
                $property,
            );
        }

        return $value;
    }

    private function readNullableString(
        #[\SensitiveParameter] \stdClass $raw,
        string $property,
    ): ?string {
        if (! property_exists($raw, $property)) {
            return null;
        }

        $value = $raw->{$property};

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                $this->classifyViolation($value),
                $property,
            );
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::InvalidUtf8,
                $property,
            );
        }

        return $value;
    }

    private function readNullableBool(
        #[\SensitiveParameter] \stdClass $raw,
        string $property,
    ): ?bool {
        if (! property_exists($raw, $property)) {
            return null;
        }

        $value = $raw->{$property};

        if ($value === null) {
            return null;
        }

        if (! is_bool($value)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                $this->classifyViolation($value),
                $property,
            );
        }

        return $value;
    }

    private function readPosition(#[\SensitiveParameter] \stdClass $raw): ?int
    {
        if (! property_exists($raw, 'position')) {
            return null;
        }

        $value = $raw->position;

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                $this->classifyViolation($value),
                'position',
            );
        }

        if ($value < 0) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::NegativeInteger,
                'position',
            );
        }

        return $value;
    }

    private function mapFrontendInput(#[\SensitiveParameter] string $frontendInput): string
    {
        return match ($frontendInput) {
            'text' => 'text',
            'textarea' => 'long_text',
            'texteditor' => 'long_text',
            'date' => 'date',
            'datetime' => 'datetime',
            'boolean' => 'boolean',
            'select' => 'select',
            'multiselect' => 'multi_select',
            'price' => 'money',
            'media_image' => 'image',
            'gallery' => 'image_collection',
            default => throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::UnmappedValue,
                'frontend_input',
            ),
        };
    }

    private function mapScope(#[\SensitiveParameter] string $scope): string
    {
        return match ($scope) {
            'global' => 'global',
            'website' => 'website',
            'store' => 'store',
            default => throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::UnmappedValue,
                'scope',
            ),
        };
    }

    private function buildNormalizedPayload(
        #[\SensitiveParameter] \stdClass $raw,
        #[\SensitiveParameter] string $frontendInput,
    ): CanonicalSchemaPayload {
        if (! in_array($frontendInput, ['select', 'multiselect'], true)) {
            return CanonicalSchemaPayload::empty();
        }

        if (! property_exists($raw, 'options')) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::MissingRequiredValue,
                'options',
            );
        }

        $options = $raw->options;

        if ($options === null || ! is_array($options) || ! array_is_list($options)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::MalformedList,
                'options',
            );
        }

        $canonicalOptions = [];

        foreach ($options as $index => $row) {
            if (! $row instanceof \stdClass) {
                throw ConnectorDiscoverySchemaValidationException::at(
                    ConnectorDiscoverySchemaValidationReason::MalformedObject,
                    "options[{$index}]",
                );
            }

            $optionPath = "options[{$index}]";

            if (! property_exists($row, 'value')) {
                throw ConnectorDiscoverySchemaValidationException::at(
                    ConnectorDiscoverySchemaValidationReason::MissingRequiredValue,
                    "{$optionPath}.value",
                );
            }

            $label = property_exists($row, 'label') ? $row->label : null;
            $canonicalOptions[] = CanonicalSchemaOption::fromRaw($row->value, $label, $optionPath);
        }

        return CanonicalSchemaPayload::withOptions($canonicalOptions);
    }

    /**
     * @return ConnectorDiscoverySchemaValidationReason::InvalidType|ConnectorDiscoverySchemaValidationReason::UnsupportedCanonicalValue
     */
    private function classifyViolation(#[\SensitiveParameter] mixed $value): ConnectorDiscoverySchemaValidationReason
    {
        if ($value === null || is_scalar($value)) {
            return ConnectorDiscoverySchemaValidationReason::InvalidType;
        }

        return ConnectorDiscoverySchemaValidationReason::UnsupportedCanonicalValue;
    }
}
