<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;

final class CanonicalSchemaOption
{
    private function __construct(
        private readonly string $value,
        private readonly ?string $label,
    ) {}

    public static function fromRaw(
        #[\SensitiveParameter] mixed $value,
        #[\SensitiveParameter] mixed $label,
        string $optionPath,
    ): self {
        $valuePath = "{$optionPath}.value";
        $labelPath = "{$optionPath}.label";

        if (! is_string($value)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                self::classifyViolation($value) ?? ConnectorDiscoverySchemaValidationReason::InvalidType,
                $valuePath,
            );
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::InvalidUtf8,
                $valuePath,
            );
        }

        if ($label !== null) {
            if (! is_string($label)) {
                throw ConnectorDiscoverySchemaValidationException::at(
                    self::classifyViolation($label) ?? ConnectorDiscoverySchemaValidationReason::InvalidType,
                    $labelPath,
                );
            }

            if (! mb_check_encoding($label, 'UTF-8')) {
                throw ConnectorDiscoverySchemaValidationException::at(
                    ConnectorDiscoverySchemaValidationReason::InvalidUtf8,
                    $labelPath,
                );
            }
        }

        return new self($value, $label);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function label(): ?string
    {
        return $this->label;
    }

    /**
     * @return ConnectorDiscoverySchemaValidationReason::InvalidType|ConnectorDiscoverySchemaValidationReason::UnsupportedCanonicalValue
     */
    private static function classifyViolation(mixed $value): ConnectorDiscoverySchemaValidationReason
    {
        if ($value === null || is_scalar($value)) {
            return ConnectorDiscoverySchemaValidationReason::InvalidType;
        }

        return ConnectorDiscoverySchemaValidationReason::UnsupportedCanonicalValue;
    }
}
