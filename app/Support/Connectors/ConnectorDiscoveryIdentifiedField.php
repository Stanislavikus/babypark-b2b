<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Enums\ConnectorSchemaFieldNormalizationStatus;

final readonly class ConnectorDiscoveryIdentifiedField
{
    private function __construct(
        private string $externalFieldKey,
        private ?string $externalLabel,
        private ?CanonicalSchemaField $canonicalField,
        private CanonicalSchemaPayload $normalizedPayload,
        private ConnectorSchemaFieldNormalizationStatus $normalizationStatus,
        private ?ConnectorDiscoverySchemaValidationReason $normalizationFailureReason,
    ) {}

    public static function normalized(CanonicalSchemaField $field): self
    {
        return new self(
            $field->externalFieldKey(),
            $field->externalLabel(),
            $field,
            $field->normalizedPayload(),
            ConnectorSchemaFieldNormalizationStatus::Normalized,
            null,
        );
    }

    public static function unclassified(
        string $externalFieldKey,
        ?string $externalLabel,
        CanonicalSchemaPayload $normalizedPayload,
        ?ConnectorDiscoverySchemaValidationReason $reason,
    ): self {
        if ($externalFieldKey === '' || ! mb_check_encoding($externalFieldKey, 'UTF-8')) {
            throw new \InvalidArgumentException('Unclassified field requires a valid external field key.');
        }

        return new self(
            $externalFieldKey,
            $externalLabel,
            null,
            $normalizedPayload,
            ConnectorSchemaFieldNormalizationStatus::Unclassified,
            $reason,
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

    public function normalizationStatus(): ConnectorSchemaFieldNormalizationStatus
    {
        return $this->normalizationStatus;
    }

    public function normalizationFailureReason(): ?ConnectorDiscoverySchemaValidationReason
    {
        return $this->normalizationFailureReason;
    }

    public function normalizedDataType(): ?string
    {
        return $this->canonicalField?->normalizedDataType();
    }

    public function isRequired(): ?bool
    {
        return $this->canonicalField?->isRequired();
    }

    public function isMultiValue(): ?bool
    {
        return $this->canonicalField?->isMultiValue();
    }

    public function isLocalizable(): ?bool
    {
        return $this->canonicalField?->isLocalizable();
    }

    public function externalScope(): ?string
    {
        return $this->canonicalField?->externalScope();
    }

    public function normalizedPayload(): CanonicalSchemaPayload
    {
        return $this->normalizedPayload;
    }

    public function sortOrder(): ?int
    {
        return $this->canonicalField?->sortOrder();
    }

    public function isNormalized(): bool
    {
        return $this->normalizationStatus === ConnectorSchemaFieldNormalizationStatus::Normalized;
    }
}
