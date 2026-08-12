<?php

namespace App\Services\Sync;

use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Support\CanonicalRegistry\CanonicalRegistryReader;

final class CanonicalFieldMappingSuggestionProvider
{
    public function __construct(
        private readonly CanonicalRegistryReader $registryReader,
    ) {}

    /**
     * @param  array<string, true>  $snapshotExternalFieldKeys
     * @param  array<string, true>  $reservedBindingIds
     * @param  array<string, true>  $reservedExternalKeys
     * @return array<string, string> field_binding_id => external_field_key
     */
    public function suggest(
        string $connectorDefinitionCode,
        array $snapshotExternalFieldKeys,
        array $reservedBindingIds,
        array $reservedExternalKeys,
    ): array {
        $channelMappings = $this->verifiedMappingsForChannel($connectorDefinitionCode);

        if ($channelMappings === []) {
            return [];
        }

        if ($snapshotExternalFieldKeys === []) {
            return [];
        }

        $fieldsByCode = $this->indexFieldsByInternalCode();
        $definitionsByCode = $this->loadGlobalActiveDefinitions(
            $this->collectInternalCodes($channelMappings, $fieldsByCode),
        );
        $bindingsByDefinitionId = $this->loadActiveProductBindings($definitionsByCode);

        /** @var list<array{binding_id: string, external_key: string}> $rawCandidates */
        $rawCandidates = [];

        foreach ($channelMappings as $mappingRow) {
            $internalCode = $mappingRow['internal_code'];
            $fieldRow = $fieldsByCode[$internalCode] ?? null;

            if ($fieldRow === null || ! $this->fieldRowQualifies($fieldRow)) {
                continue;
            }

            $canonicalScope = $fieldRow['scope'];
            $definitionScope = $this->mapCanonicalScope($canonicalScope);

            if ($definitionScope === null) {
                continue;
            }

            $definition = $definitionsByCode[$internalCode] ?? null;

            if ($definition === null || $definition->scope !== $definitionScope) {
                continue;
            }

            $externalField = $mappingRow['external_field'];

            if (! isset($snapshotExternalFieldKeys[$externalField])) {
                continue;
            }

            $objectTypes = $this->objectTypesForBindingStrategy($fieldRow['binding_strategy']);

            foreach ($objectTypes as $objectType) {
                $binding = $this->findBindingForDefinition(
                    $bindingsByDefinitionId,
                    $definition->id,
                    $objectType,
                );

                if ($binding === null) {
                    continue;
                }

                $rawCandidates[] = [
                    'binding_id' => $binding->id,
                    'external_key' => $externalField,
                ];
            }
        }

        return $this->resolveSuggestionCollisions(
            $rawCandidates,
            $reservedBindingIds,
            $reservedExternalKeys,
        );
    }

    /**
     * @return list<array<string, string>>
     */
    private function verifiedMappingsForChannel(string $connectorDefinitionCode): array
    {
        return array_values(array_filter(
            $this->registryReader->mappings(),
            fn (array $row): bool => $row['channel'] === $connectorDefinitionCode
                && $row['verification_status'] === 'verified',
        ));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function indexFieldsByInternalCode(): array
    {
        $indexed = [];

        foreach ($this->registryReader->fields() as $row) {
            $indexed[$row['internal_code']] = $row;
        }

        return $indexed;
    }

    /**
     * @param  list<array<string, string>>  $channelMappings
     * @param  array<string, array<string, string>>  $fieldsByCode
     * @return list<string>
     */
    private function collectInternalCodes(array $channelMappings, array $fieldsByCode): array
    {
        $codes = [];

        foreach ($channelMappings as $mappingRow) {
            $internalCode = $mappingRow['internal_code'];
            $fieldRow = $fieldsByCode[$internalCode] ?? null;

            if ($fieldRow !== null && $this->fieldRowQualifies($fieldRow)) {
                $codes[] = $internalCode;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, FieldDefinition>
     */
    private function loadGlobalActiveDefinitions(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return FieldDefinition::withoutWorkspaceScope()
            ->whereNull('workspace_id')
            ->whereIn('code', $codes)
            ->where('status', AttributeStatus::Active)
            ->get()
            ->keyBy('code')
            ->all();
    }

    /**
     * @param  array<string, FieldDefinition>  $definitionsByCode
     * @return array<string, list<FieldBinding>>
     */
    private function loadActiveProductBindings(array $definitionsByCode): array
    {
        $definitionIds = array_map(
            fn (FieldDefinition $definition): string => $definition->id,
            $definitionsByCode,
        );

        if ($definitionIds === []) {
            return [];
        }

        $grouped = [];

        foreach (
            FieldBinding::withoutWorkspaceScope()
                ->whereIn('field_definition_id', $definitionIds)
                ->where('status', AttributeStatus::Active)
                ->whereIn('object_type', [FieldObjectType::Product, FieldObjectType::ProductVariant])
                ->get() as $binding
        ) {
            $grouped[$binding->field_definition_id][] = $binding;
        }

        return $grouped;
    }

    /**
     * @param  array<string, string>  $fieldRow
     */
    private function fieldRowQualifies(array $fieldRow): bool
    {
        return $fieldRow['field_definition_eligibility'] === 'yes'
            && $fieldRow['status'] === 'active'
            && $fieldRow['verification_status'] === 'verified'
            && in_array($fieldRow['scope'], ['system', 'platform_library'], true);
    }

    private function mapCanonicalScope(string $canonicalScope): ?AttributeScope
    {
        return match ($canonicalScope) {
            'system' => AttributeScope::System,
            'platform_library' => AttributeScope::PlatformLibrary,
            default => null,
        };
    }

    /**
     * @return list<FieldObjectType>
     */
    private function objectTypesForBindingStrategy(string $bindingStrategy): array
    {
        return match ($bindingStrategy) {
            'product' => [FieldObjectType::Product],
            'product_variant' => [FieldObjectType::ProductVariant],
            'product_and_variant_two_bindings' => [FieldObjectType::Product, FieldObjectType::ProductVariant],
            default => [],
        };
    }

    /**
     * @param  array<string, list<FieldBinding>>  $bindingsByDefinitionId
     */
    private function findBindingForDefinition(
        array $bindingsByDefinitionId,
        string $definitionId,
        FieldObjectType $objectType,
    ): ?FieldBinding {
        foreach ($bindingsByDefinitionId[$definitionId] ?? [] as $binding) {
            if ($binding->object_type === $objectType) {
                return $binding;
            }
        }

        return null;
    }

    /**
     * @param  list<array{binding_id: string, external_key: string}>  $rawCandidates
     * @param  array<string, true>  $reservedBindingIds
     * @param  array<string, true>  $reservedExternalKeys
     * @return array<string, string>
     */
    private function resolveSuggestionCollisions(
        array $rawCandidates,
        array $reservedBindingIds,
        array $reservedExternalKeys,
    ): array {
        $eligible = array_values(array_filter(
            $rawCandidates,
            fn (array $candidate): bool => ! isset($reservedBindingIds[$candidate['binding_id']])
                && ! isset($reservedExternalKeys[$candidate['external_key']]),
        ));

        if ($eligible === []) {
            return [];
        }

        /** @var array<string, list<string>> $keysByBinding */
        $keysByBinding = [];
        /** @var array<string, list<string>> $bindingsByKey */
        $bindingsByKey = [];

        foreach ($eligible as $candidate) {
            $keysByBinding[$candidate['binding_id']][] = $candidate['external_key'];
            $bindingsByKey[$candidate['external_key']][] = $candidate['binding_id'];
        }

        $collidingBindings = [];
        $collidingKeys = [];

        foreach ($keysByBinding as $bindingId => $keys) {
            if (count(array_unique($keys)) > 1) {
                $collidingBindings[$bindingId] = true;
            }
        }

        foreach ($bindingsByKey as $externalKey => $bindingIds) {
            if (count(array_unique($bindingIds)) > 1) {
                $collidingKeys[$externalKey] = true;
            }
        }

        $suggestions = [];

        foreach ($eligible as $candidate) {
            if (isset($collidingBindings[$candidate['binding_id']]) || isset($collidingKeys[$candidate['external_key']])) {
                continue;
            }

            $suggestions[$candidate['binding_id']] = $candidate['external_key'];
        }

        return $suggestions;
    }
}
