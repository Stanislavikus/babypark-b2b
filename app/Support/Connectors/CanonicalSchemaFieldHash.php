<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;

final class CanonicalSchemaFieldHash
{
    private function __construct(
        private readonly string $externalFieldKey,
        private readonly string $canonicalHash,
    ) {}

    public static function create(
        #[\SensitiveParameter] mixed $externalFieldKey,
        #[\SensitiveParameter] mixed $canonicalHash,
    ): self {
        if (! is_string($externalFieldKey)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                self::classifyViolation($externalFieldKey) ?? ConnectorDiscoverySchemaValidationReason::InvalidType,
                'external_field_key',
            );
        }

        if ($externalFieldKey === '') {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::EmptyRequiredString,
                'external_field_key',
            );
        }

        if (! mb_check_encoding($externalFieldKey, 'UTF-8')) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::InvalidUtf8,
                'external_field_key',
            );
        }

        if (! is_string($canonicalHash)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                self::classifyViolation($canonicalHash) ?? ConnectorDiscoverySchemaValidationReason::InvalidType,
                'canonical_hash',
            );
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $canonicalHash)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::InvalidCanonicalHash,
                'canonical_hash',
            );
        }

        return new self($externalFieldKey, $canonicalHash);
    }

    public function externalFieldKey(): string
    {
        return $this->externalFieldKey;
    }

    public function canonicalHash(): string
    {
        return $this->canonicalHash;
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
