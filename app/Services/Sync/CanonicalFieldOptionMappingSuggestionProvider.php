<?php

namespace App\Services\Sync;

use App\Enums\FieldObjectType;
use App\Support\CanonicalRegistry\CanonicalRegistryReader;

final class CanonicalFieldOptionMappingSuggestionProvider
{
    public function __construct(
        private readonly CanonicalRegistryReader $registryReader,
    ) {}

    /**
     * @param  list<string>  $internalOptionKeys
     * @param  list<string>  $authoritativeExternalValues
     * @param  list<string>  $reservedExternalValues
     * @return array<string, string> internal_option_key => external_option_value
     */
    public function suggest(
        string $connectorDefinitionCode,
        string $internalFieldCode,
        FieldObjectType $objectType,
        string $externalFieldKey,
        array $internalOptionKeys,
        array $authoritativeExternalValues,
        array $reservedExternalValues = [],
    ): array {
        if ($internalOptionKeys === [] || $authoritativeExternalValues === []) {
            return [];
        }

        $applicabilityById = $this->verifiedApplicabilityById();

        if (! $this->hasVerifiedFieldMapping(
            $connectorDefinitionCode,
            $internalFieldCode,
            $objectType,
            $externalFieldKey,
            $applicabilityById,
        )) {
            return [];
        }

        $internalOptionKeySet = array_fill_keys($internalOptionKeys, true);
        $externalValueSet = array_fill_keys($authoritativeExternalValues, true);
        $reservedExternalValueSet = array_fill_keys($reservedExternalValues, true);
        $optionCodeById = [];

        foreach ($this->registryReader->options() as $row) {
            if (($row['internal_code'] ?? '') !== $internalFieldCode
                || ($row['verification_status'] ?? '') !== 'verified'
                || ($row['status'] ?? '') !== 'active') {
                continue;
            }

            $applicability = $applicabilityById[$row['applicability_id'] ?? ''] ?? null;

            if (! $this->applicabilityQualifies($applicability, $connectorDefinitionCode, $objectType)) {
                continue;
            }

            $optionCode = $row['option_code'] ?? '';
            $optionId = $row['option_id'] ?? '';

            if ($optionCode === '' || $optionId === '' || ! isset($internalOptionKeySet[$optionCode])) {
                continue;
            }

            $optionCodeById[$optionId] = $optionCode;
        }

        if ($optionCodeById === []) {
            return [];
        }

        /** @var array<string, array<string, true>> $candidatesByInternalOption */
        $candidatesByInternalOption = [];
        /** @var array<string, array<string, true>> $internalOptionsByExternalValue */
        $internalOptionsByExternalValue = [];

        foreach ($this->registryReader->optionMappings() as $row) {
            if (($row['channel'] ?? '') !== $connectorDefinitionCode
                || ($row['verification_status'] ?? '') !== 'verified') {
                continue;
            }

            $applicability = $applicabilityById[$row['applicability_id'] ?? ''] ?? null;

            if (! $this->applicabilityQualifies($applicability, $connectorDefinitionCode, $objectType)) {
                continue;
            }

            $optionCode = $optionCodeById[$row['option_id'] ?? ''] ?? null;
            $externalValue = $row['external_option_value'] ?? '';

            if ($optionCode === null
                || $externalValue === ''
                || ! isset($externalValueSet[$externalValue])
                || isset($reservedExternalValueSet[$externalValue])) {
                continue;
            }

            $candidatesByInternalOption[$optionCode][$externalValue] = true;
            $internalOptionsByExternalValue[$externalValue][$optionCode] = true;
        }

        $suggestions = [];

        foreach ($internalOptionKeys as $internalOptionKey) {
            $candidates = array_keys($candidatesByInternalOption[$internalOptionKey] ?? []);

            if (count($candidates) !== 1) {
                continue;
            }

            $externalValue = $candidates[0];

            if (count($internalOptionsByExternalValue[$externalValue] ?? []) !== 1) {
                continue;
            }

            $suggestions[$internalOptionKey] = $externalValue;
        }

        return $suggestions;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function verifiedApplicabilityById(): array
    {
        $rows = [];

        foreach ($this->registryReader->applicability() as $row) {
            $id = $row['applicability_id'] ?? '';

            if ($id === '' || ($row['verification_status'] ?? '') !== 'verified') {
                continue;
            }

            $rows[$id] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, string>|null  $applicability
     */
    private function applicabilityQualifies(
        ?array $applicability,
        string $connectorDefinitionCode,
        FieldObjectType $objectType,
    ): bool {
        if ($applicability === null
            || ! in_array($applicability['context_type'] ?? '', ['global', 'channel'], true)
            || ($applicability['entity_level'] ?? '') !== $objectType->value) {
            return false;
        }

        return ($applicability['context_type'] ?? '') !== 'channel'
            || ($applicability['channel_or_state'] ?? '') === $connectorDefinitionCode;
    }

    /**
     * @param  array<string, array<string, string>>  $applicabilityById
     */
    private function hasVerifiedFieldMapping(
        string $connectorDefinitionCode,
        string $internalFieldCode,
        FieldObjectType $objectType,
        string $externalFieldKey,
        array $applicabilityById,
    ): bool {
        foreach ($this->registryReader->mappings() as $row) {
            if (($row['channel'] ?? '') !== $connectorDefinitionCode
                || ($row['internal_code'] ?? '') !== $internalFieldCode
                || ($row['external_field'] ?? '') !== $externalFieldKey
                || ($row['verification_status'] ?? '') !== 'verified') {
                continue;
            }

            $applicability = $applicabilityById[$row['applicability_id'] ?? ''] ?? null;

            if ($this->applicabilityQualifies($applicability, $connectorDefinitionCode, $objectType)) {
                return true;
            }
        }

        return false;
    }
}
