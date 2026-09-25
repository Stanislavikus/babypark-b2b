<?php

namespace App\Support\Connectors\AdobePaaS\Receive;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Enums\ReceiveDomainRoute;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;
use App\Models\FieldOptionMapping;
use App\Models\ProductFieldValue;
use App\Models\SyncConfiguration;
use App\Models\VariantFieldValue;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocument;
use App\Support\Sync\Receive\ReceiveFieldCandidate;
use Illuminate\Support\Collection;

final class AdobeProductDynamicSelectReceiveResolver
{
    /** @return list<ReceiveFieldCandidate> */
    public function candidates(
        SyncConfiguration $configuration,
        AdobeProductDocument $document,
        FieldObjectType $targetType,
        string $targetId,
    ): array {
        $candidates = [];

        foreach ($this->eligibleMappings($configuration, $targetType) as $mapping) {
            $state = $this->stateForMapping($configuration, $document, $mapping, $targetType, $targetId);

            if (! $state->remoteValuePresent && $state->blockedReasonCode === null) {
                continue;
            }

            $candidates[] = new ReceiveFieldCandidate(
                fieldBindingId: $state->fieldBindingId,
                objectType: $state->objectType,
                domainRoute: $state->isSupported ? ReceiveDomainRoute::DynamicField : ReceiveDomainRoute::Unsupported,
                localValuePresent: $state->localValuePresent,
                localCanonicalValue: $state->localCanonicalValue,
                remoteValuePresent: $state->remoteValuePresent,
                remoteCanonicalValue: $state->remoteCanonicalValue,
                explicitClear: false,
                isSupported: $state->isSupported,
                blockedReasonCode: $state->blockedReasonCode,
            );
        }

        return $candidates;
    }

    public function hasEligibleMapping(
        SyncConfiguration $configuration,
        FieldObjectType $targetType,
        string $fieldBindingId,
    ): bool {
        return $this->eligibleMappings($configuration, $targetType)
            ->contains(fn (FieldMapping $mapping): bool => (string) $mapping->field_binding_id === $fieldBindingId);
    }

    public function stateForBinding(
        SyncConfiguration $configuration,
        AdobeProductDocument $document,
        FieldObjectType $targetType,
        string $targetId,
        string $fieldBindingId,
    ): ?AdobeProductDynamicSelectReceiveState {
        $mapping = $this->eligibleMappings($configuration, $targetType)
            ->first(fn (FieldMapping $candidate): bool => (string) $candidate->field_binding_id === $fieldBindingId);

        if (! $mapping instanceof FieldMapping) {
            return null;
        }

        return $this->stateForMapping($configuration, $document, $mapping, $targetType, $targetId);
    }

    private function eligibleMappings(SyncConfiguration $configuration, FieldObjectType $targetType): Collection
    {
        return FieldMapping::withoutWorkspaceScope()
            ->where('workspace_id', $configuration->workspace_id)
            ->where('sync_configuration_id', $configuration->id)
            ->with(['fieldBinding.fieldDefinition', 'optionMappings'])
            ->get()
            ->filter(function (FieldMapping $mapping) use ($configuration, $targetType): bool {
                $binding = $mapping->fieldBinding;
                $definition = $binding?->fieldDefinition;

                return $binding instanceof FieldBinding
                    && $definition instanceof FieldDefinition
                    && $binding->workspace_id === $configuration->workspace_id
                    && $definition->workspace_id === $configuration->workspace_id
                    && $binding->status === AttributeStatus::Active
                    && $definition->status === AttributeStatus::Active
                    && $binding->object_type === $targetType
                    && $binding->storage_type === AttributeStorageType::Dynamic
                    && $definition->scope === AttributeScope::WorkspaceCustom
                    && $definition->data_type === AttributeDataType::Select
                    && ! $definition->is_multi_value
                    && ! $definition->is_localizable;
            })
            ->values();
    }

    private function stateForMapping(
        SyncConfiguration $configuration,
        AdobeProductDocument $document,
        FieldMapping $mapping,
        FieldObjectType $targetType,
        string $targetId,
    ): AdobeProductDynamicSelectReceiveState {
        $binding = $mapping->fieldBinding;
        $definition = $binding->fieldDefinition;
        [$localPresent, $localValue, $localBlocked] = $this->localValue(
            $configuration,
            $binding,
            $definition,
            $targetType,
            $targetId,
        );
        $remote = $document->externalValue((string) $mapping->external_field_key);

        if (! ($remote['present'] ?? false)) {
            return new AdobeProductDynamicSelectReceiveState(
                fieldMappingId: (string) $mapping->id,
                fieldBindingId: (string) $binding->id,
                externalFieldKey: (string) $mapping->external_field_key,
                objectType: $targetType,
                localValuePresent: $localPresent,
                localCanonicalValue: $localValue,
                remoteValuePresent: false,
                remoteCanonicalValue: null,
                isSupported: $localBlocked === null,
                blockedReasonCode: $localBlocked,
            );
        }

        $externalValue = $this->normalizeExternalOptionValue($remote['value'] ?? null);
        if ($externalValue === null) {
            return $this->blockedState($mapping, $targetType, $localPresent, $localValue, 'dynamic_select_remote_value_invalid');
        }

        $matches = $mapping->optionMappings
            ->filter(fn (FieldOptionMapping $option): bool => (string) $option->external_option_value === $externalValue)
            ->values();

        if ($matches->count() === 0) {
            return $this->blockedState($mapping, $targetType, $localPresent, $localValue, 'dynamic_select_option_mapping_missing');
        }

        if ($matches->count() !== 1) {
            return $this->blockedState($mapping, $targetType, $localPresent, $localValue, 'dynamic_select_option_mapping_ambiguous');
        }

        $internalOptionKey = (string) $matches->first()->internal_option_key;
        if (! $this->definitionDeclaresOption($definition, $internalOptionKey)) {
            return $this->blockedState($mapping, $targetType, $localPresent, $localValue, 'dynamic_select_option_mapping_invalid');
        }

        if ($localBlocked !== null) {
            return $this->blockedState($mapping, $targetType, $localPresent, $localValue, $localBlocked, $internalOptionKey);
        }

        return new AdobeProductDynamicSelectReceiveState(
            fieldMappingId: (string) $mapping->id,
            fieldBindingId: (string) $binding->id,
            externalFieldKey: (string) $mapping->external_field_key,
            objectType: $targetType,
            localValuePresent: $localPresent,
            localCanonicalValue: $localValue,
            remoteValuePresent: true,
            remoteCanonicalValue: $internalOptionKey,
            isSupported: true,
            blockedReasonCode: null,
        );
    }

    /** @return array{0: bool, 1: ?string, 2: ?string} */
    private function localValue(
        SyncConfiguration $configuration,
        FieldBinding $binding,
        FieldDefinition $definition,
        FieldObjectType $targetType,
        string $targetId,
    ): array {
        $row = match ($targetType) {
            FieldObjectType::Product => ProductFieldValue::withoutWorkspaceScope()
                ->where('workspace_id', $configuration->workspace_id)
                ->where('product_id', $targetId)
                ->where('field_binding_id', $binding->id)
                ->first(),
            FieldObjectType::ProductVariant => VariantFieldValue::withoutWorkspaceScope()
                ->where('workspace_id', $configuration->workspace_id)
                ->where('variant_id', $targetId)
                ->where('field_binding_id', $binding->id)
                ->first(),
            default => null,
        };

        if ($row === null) {
            return [false, null, null];
        }

        if (! is_string($row->value_text) || $row->value_num !== null || $row->value_jsonb !== null) {
            return [true, null, 'dynamic_select_local_storage_invalid'];
        }

        if (! $this->definitionDeclaresOption($definition, $row->value_text)) {
            return [true, $row->value_text, 'dynamic_select_local_option_invalid'];
        }

        return [true, $row->value_text, null];
    }

    private function normalizeExternalOptionValue(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function definitionDeclaresOption(FieldDefinition $definition, string $internalOptionKey): bool
    {
        $options = $definition->validation_rules['options'] ?? null;

        if (! is_array($options) || ! array_is_list($options)) {
            return false;
        }

        foreach ($options as $option) {
            if (is_array($option) && ($option['code'] ?? null) === $internalOptionKey) {
                return true;
            }
        }

        return false;
    }

    private function blockedState(
        FieldMapping $mapping,
        FieldObjectType $targetType,
        bool $localPresent,
        ?string $localValue,
        string $reason,
        ?string $remoteCanonicalValue = null,
    ): AdobeProductDynamicSelectReceiveState {
        return new AdobeProductDynamicSelectReceiveState(
            fieldMappingId: (string) $mapping->id,
            fieldBindingId: (string) $mapping->field_binding_id,
            externalFieldKey: (string) $mapping->external_field_key,
            objectType: $targetType,
            localValuePresent: $localPresent,
            localCanonicalValue: $localValue,
            remoteValuePresent: true,
            remoteCanonicalValue: $remoteCanonicalValue,
            isSupported: false,
            blockedReasonCode: $reason,
        );
    }
}
