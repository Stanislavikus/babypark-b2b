<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\ConnectorSchemaFieldDisposition;
use App\Enums\ConnectorSchemaFieldNormalizationStatus;
use App\Models\ConnectorSchemaSnapshotField;
use App\Support\CanonicalRegistry\CanonicalRegistryReader;
use App\Support\Connectors\ConnectorSchemaFieldClassificationDecision;

final class AdobeProductAttributeClassifier
{
    public const CLASSIFIER_VERSION = 'adobe.product_attribute_classifier.v1';

    public function __construct(
        private readonly CanonicalRegistryReader $registryReader,
        private readonly AdobeProductAttributeProviderRegistry $providerRegistry,
    ) {}

    public function classify(ConnectorSchemaSnapshotField $field): ConnectorSchemaFieldClassificationDecision
    {
        $payload = $this->asArray($field->normalized_payload);
        $metadataVersion = $payload['provider_metadata_version'] ?? null;
        $metadata = $this->asArray($payload['provider_metadata'] ?? null);

        if ($metadataVersion !== AdobePaaSAttributePayloadProjector::PROVIDER_METADATA_VERSION || $metadata === []) {
            return $this->review(null, null, 'provider_metadata_missing_or_unknown');
        }

        $providerEntry = $this->providerRegistry->find($field->external_field_key);
        [$behaviorClass, $behaviorSignature] = $this->behavior(
            $field,
            $payload,
            $metadata,
            $providerEntry['owner'] ?? null,
        );

        if ($this->hasThirdPartySpecialModel($metadata)) {
            return $this->review($behaviorClass, $behaviorSignature, 'third_party_special_model');
        }

        $canonicalCodes = $this->canonicalCodesForExternalKey($field->external_field_key);
        if (count($canonicalCodes) > 1) {
            return $this->review($behaviorClass, $behaviorSignature, 'canonical_mapping_collision');
        }
        $canonicalCode = $canonicalCodes[0] ?? null;

        $channelDecisions = $this->verifiedChannelDecisionsForInternalCode($field->external_field_key);
        if (count($channelDecisions) > 1) {
            return $this->review($behaviorClass, $behaviorSignature, 'canonical_channel_decision_collision');
        }
        $channelDecision = $channelDecisions[0] ?? null;

        if ($providerEntry !== null && $providerEntry['owner'] !== null) {
            return new ConnectorSchemaFieldClassificationDecision(
                ConnectorSchemaFieldDisposition::SystemOrDedicatedOwner,
                $behaviorClass,
                $behaviorSignature,
                $providerEntry['owner'],
                $canonicalCode,
                'dedicated_owner',
                self::CLASSIFIER_VERSION,
                'provider_registry_dedicated_owner',
            );
        }

        if ($canonicalCode !== null) {
            if (($channelDecision['decision_state'] ?? null) === 'account_specific') {
                return $this->review($behaviorClass, $behaviorSignature, 'verified_mapping_conflicts_with_account_specific_decision');
            }

            return new ConnectorSchemaFieldClassificationDecision(
                ConnectorSchemaFieldDisposition::CanonicalPlatform,
                $behaviorClass,
                $behaviorSignature,
                null,
                $canonicalCode,
                'verified_channel_mapping_rule',
                self::CLASSIFIER_VERSION,
                'verified_canonical_mapping',
            );
        }

        if ($channelDecision !== null) {
            $decisionState = $channelDecision['decision_state'] ?? '';

            if ($decisionState === 'account_specific') {
                if ($field->normalization_status === ConnectorSchemaFieldNormalizationStatus::Unclassified) {
                    return $this->review($behaviorClass, $behaviorSignature, 'semantic_normalization_failed');
                }

                if (($metadata['is_user_defined'] ?? null) === true) {
                    return new ConnectorSchemaFieldClassificationDecision(
                        ConnectorSchemaFieldDisposition::WorkspaceCustom,
                        $behaviorClass,
                        $behaviorSignature,
                        null,
                        null,
                        'stage2_workspace_materialization',
                        self::CLASSIFIER_VERSION,
                        'verified_channel_account_specific',
                    );
                }

                return $this->review($behaviorClass, $behaviorSignature, 'account_specific_provider_metadata_conflict');
            }

            if ($decisionState === 'deferred') {
                $canonicalField = $this->canonicalFieldForCode($field->external_field_key);
                if ($providerEntry === null) {
                    return $this->review($behaviorClass, $behaviorSignature, 'deferred_canonical_provider_identity_not_proven');
                }
                if (! $this->isActiveVerifiedCanonicalField($canonicalField)) {
                    return $this->review($behaviorClass, $behaviorSignature, 'deferred_canonical_not_active_verified');
                }

                return new ConnectorSchemaFieldClassificationDecision(
                    ConnectorSchemaFieldDisposition::CanonicalPlatform,
                    $behaviorClass,
                    $behaviorSignature,
                    null,
                    $field->external_field_key,
                    'channel_deferred',
                    self::CLASSIFIER_VERSION,
                    'verified_channel_decision_deferred',
                );
            }

            return $this->review($behaviorClass, $behaviorSignature, 'unsupported_verified_channel_decision_state');
        }

        if ($providerEntry !== null) {
            return new ConnectorSchemaFieldClassificationDecision(
                ConnectorSchemaFieldDisposition::ProviderStandard,
                $behaviorClass,
                $behaviorSignature,
                null,
                null,
                'existing_internal_or_manual_mapping',
                self::CLASSIFIER_VERSION,
                'provider_registry_standard_attribute',
            );
        }

        if ($field->normalization_status === ConnectorSchemaFieldNormalizationStatus::Unclassified) {
            return $this->review($behaviorClass, $behaviorSignature, 'semantic_normalization_failed');
        }

        if (($metadata['is_user_defined'] ?? null) === true) {
            return new ConnectorSchemaFieldClassificationDecision(
                ConnectorSchemaFieldDisposition::WorkspaceCustom,
                $behaviorClass,
                $behaviorSignature,
                null,
                null,
                'stage2_workspace_materialization',
                self::CLASSIFIER_VERSION,
                'merchant_defined_attribute',
            );
        }

        if ($this->canonicalFieldForCode($field->external_field_key) !== null) {
            return $this->review($behaviorClass, $behaviorSignature, 'canonical_name_match_without_channel_proof');
        }

        return $this->review($behaviorClass, $behaviorSignature, 'provider_ownership_not_proven');
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function behavior(
        ConnectorSchemaSnapshotField $field,
        array $payload,
        array $metadata,
        ?string $runtimeOwnerHint,
    ): array {
        $applyTo = $metadata['apply_to'] ?? [];
        $applyTo = is_array($applyTo) ? array_values(array_unique(array_filter($applyTo, 'is_string'))) : [];
        sort($applyTo, SORT_STRING);

        $signature = [
            'provider' => 'adobe_commerce',
            'surface' => 'product_attribute',
            'schema_version' => 'v1',
            'normalized_type' => $field->normalized_data_type,
            'frontend_input' => $metadata['frontend_input'] ?? null,
            'backend_type' => $metadata['backend_type'] ?? null,
            'scope' => $field->external_scope ?? ($metadata['scope'] ?? null),
            'required' => $field->is_required,
            'multi_value' => $field->is_multi_value,
            'localizable' => $field->is_localizable,
            'option_semantics' => $this->optionSemantics($payload, $metadata),
            'source_model' => $this->nullableNonEmptyString($metadata['source_model'] ?? null),
            'backend_model' => $this->nullableNonEmptyString($metadata['backend_model'] ?? null),
            'apply_to' => $applyTo,
            'runtime_owner' => $runtimeOwnerHint ?? 'generic_field_candidate',
            'clear_semantics' => 'not_discovered',
        ];

        $canonical = $this->sortRecursively($signature);
        $json = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ['adobe.product_attribute.v1.'.substr(hash('sha256', $json), 0, 24), $canonical];
    }

    private function optionSemantics(array $payload, array $metadata): string
    {
        $frontendInput = $metadata['frontend_input'] ?? null;
        if (! in_array($frontendInput, ['select', 'multiselect', 'boolean'], true)) {
            return 'not_applicable';
        }

        $sourceModel = $this->nullableNonEmptyString($metadata['source_model'] ?? null);
        if ($sourceModel === 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Table') {
            return 'eav_table_options';
        }
        if ($sourceModel !== null) {
            return 'provider_source_model';
        }

        return array_key_exists('options', $payload) ? 'embedded_options' : 'options_unknown';
    }

    private function hasThirdPartySpecialModel(array $metadata): bool
    {
        foreach (['source_model', 'backend_model'] as $key) {
            $value = $this->nullableNonEmptyString($metadata[$key] ?? null);
            if ($value !== null && ! str_starts_with($value, 'Magento\\')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function canonicalCodesForExternalKey(string $externalFieldKey): array
    {
        $codes = [];
        foreach ($this->registryReader->verifiedMappingsForChannel('adobe_commerce') as $row) {
            $mappedKey = $this->snapshotKeyFromVerifiedMapping($row);
            if ($mappedKey === $externalFieldKey) {
                $codes[$row['internal_code']] = true;
            }
        }

        $result = array_keys($codes);
        sort($result, SORT_STRING);

        return $result;
    }

    /** @return list<array<string, string>> */
    private function verifiedChannelDecisionsForInternalCode(string $internalCode): array
    {
        $matches = [];
        foreach ($this->registryReader->channelDecisions() as $row) {
            if (($row['channel'] ?? '') === 'adobe_commerce'
                && ($row['internal_code'] ?? '') === $internalCode
                && ($row['verification_status'] ?? '') === 'verified') {
                $matches[] = $row;
            }
        }

        return $matches;
    }

    /** @return array<string, string>|null */
    private function canonicalFieldForCode(string $internalCode): ?array
    {
        foreach ($this->registryReader->fields() as $row) {
            if (($row['internal_code'] ?? '') === $internalCode) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string, string>|null $field */
    private function isActiveVerifiedCanonicalField(?array $field): bool
    {
        return $field !== null
            && ($field['status'] ?? '') === 'active'
            && ($field['verification_status'] ?? '') === 'verified';
    }

    /** @param array<string, string> $row */
    private function snapshotKeyFromVerifiedMapping(array $row): ?string
    {
        $external = $row['external_field'];
        if (preg_match('/^custom_attributes\\[attribute_code=([^\\]]+)\\]\\.value$/', $external, $matches) === 1) {
            return $matches[1];
        }
        if (($row['transformation'] ?? '') === 'category_relation_to_adobe_category_ids') {
            return 'category_ids';
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $external) === 1) {
            return $external;
        }

        return null;
    }

    private function review(
        ?string $behaviorClass,
        ?array $behaviorSignature,
        string $reasonCode,
    ): ConnectorSchemaFieldClassificationDecision {
        return new ConnectorSchemaFieldClassificationDecision(
            ConnectorSchemaFieldDisposition::ReviewNeeded,
            $behaviorClass,
            $behaviorSignature,
            null,
            null,
            'none_review_required',
            self::CLASSIFIER_VERSION,
            $reasonCode,
        );
    }

    /** @return array<string, mixed> */
    private function asArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \stdClass) {
            return (array) $value;
        }

        return [];
    }

    private function nullableNonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $nested) {
            $value[$key] = $this->sortRecursively($nested);
        }

        return $value;
    }
}
