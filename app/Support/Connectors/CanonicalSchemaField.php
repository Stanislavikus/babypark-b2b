<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;

final class CanonicalSchemaField
{
    private function __construct(
        #[\SensitiveParameter] private readonly string $externalFieldKey,
        #[\SensitiveParameter] private readonly ?string $externalLabel,
        #[\SensitiveParameter] private readonly string $normalizedDataType,
        #[\SensitiveParameter] private readonly ?bool $isRequired,
        #[\SensitiveParameter] private readonly ?bool $isMultiValue,
        #[\SensitiveParameter] private readonly ?bool $isLocalizable,
        #[\SensitiveParameter] private readonly ?string $externalScope,
        #[\SensitiveParameter] private readonly CanonicalSchemaPayload $normalizedPayload,
        #[\SensitiveParameter] private readonly ?int $sortOrder,
    ) {}

    public static function create(
        #[\SensitiveParameter] mixed $externalFieldKey,
        #[\SensitiveParameter] mixed $externalLabel,
        #[\SensitiveParameter] mixed $normalizedDataType,
        #[\SensitiveParameter] mixed $isRequired,
        #[\SensitiveParameter] mixed $isMultiValue,
        #[\SensitiveParameter] mixed $isLocalizable,
        #[\SensitiveParameter] mixed $externalScope,
        #[\SensitiveParameter] mixed $normalizedPayload,
        #[\SensitiveParameter] mixed $sortOrder,
    ): self {
        return new self(
            self::requireNonEmptyString($externalFieldKey, 'external_field_key'),
            self::requireNullableString($externalLabel, 'external_label'),
            self::requireNonEmptyString($normalizedDataType, 'normalized_data_type'),
            self::requireNullableBool($isRequired, 'is_required'),
            self::requireNullableBool($isMultiValue, 'is_multi_value'),
            self::requireNullableBool($isLocalizable, 'is_localizable'),
            self::requireNullableString($externalScope, 'external_scope'),
            self::requirePayload($normalizedPayload),
            self::requireNullableNonNegativeInt($sortOrder, 'sort_order'),
        );
    }

    public function externalFieldKey(): string
    {
        return $this->externalFieldKey;
    }

    public function externalLabel(): ?string
    {
        return $this->externalLabel;
    }

    public function normalizedDataType(): string
    {
        return $this->normalizedDataType;
    }

    public function isRequired(): ?bool
    {
        return $this->isRequired;
    }

    public function isMultiValue(): ?bool
    {
        return $this->isMultiValue;
    }

    public function isLocalizable(): ?bool
    {
        return $this->isLocalizable;
    }

    public function externalScope(): ?string
    {
        return $this->externalScope;
    }

    public function normalizedPayload(): CanonicalSchemaPayload
    {
        return $this->normalizedPayload;
    }

    public function sortOrder(): ?int
    {
        return $this->sortOrder;
    }

    private static function requirePayload(#[\SensitiveParameter] mixed $normalizedPayload): CanonicalSchemaPayload
    {
        if ($normalizedPayload instanceof CanonicalSchemaPayload) {
            return $normalizedPayload;
        }

        if (is_array($normalizedPayload)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::InvalidType,
                'normalized_payload',
            );
        }

        throw ConnectorDiscoverySchemaValidationException::at(
            self::classifyViolation($normalizedPayload),
            'normalized_payload',
        );
    }

    private static function requireNonEmptyString(#[\SensitiveParameter] mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                self::classifyViolation($value) ?? ConnectorDiscoverySchemaValidationReason::InvalidType,
                $path,
            );
        }

        if ($value === '') {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::EmptyRequiredString,
                $path,
            );
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::InvalidUtf8,
                $path,
            );
        }

        return $value;
    }

    private static function requireNullableString(#[\SensitiveParameter] mixed $value, string $path): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                self::classifyViolation($value) ?? ConnectorDiscoverySchemaValidationReason::InvalidType,
                $path,
            );
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::InvalidUtf8,
                $path,
            );
        }

        return $value;
    }

    private static function requireNullableBool(#[\SensitiveParameter] mixed $value, string $path): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (! is_bool($value)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                self::classifyViolation($value) ?? ConnectorDiscoverySchemaValidationReason::InvalidType,
                $path,
            );
        }

        return $value;
    }

    private static function requireNullableNonNegativeInt(#[\SensitiveParameter] mixed $value, string $path): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                self::classifyViolation($value) ?? ConnectorDiscoverySchemaValidationReason::InvalidType,
                $path,
            );
        }

        if ($value < 0) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::NegativeInteger,
                $path,
            );
        }

        return $value;
    }

    /**
     * @return ConnectorDiscoverySchemaValidationReason::InvalidType|ConnectorDiscoverySchemaValidationReason::UnsupportedCanonicalValue
     */
    private static function classifyViolation(#[\SensitiveParameter] mixed $value): ConnectorDiscoverySchemaValidationReason
    {
        if ($value === null || is_scalar($value)) {
            return ConnectorDiscoverySchemaValidationReason::InvalidType;
        }

        return ConnectorDiscoverySchemaValidationReason::UnsupportedCanonicalValue;
    }
}
