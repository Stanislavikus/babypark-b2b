<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Models\AdobeProductAttributeLineage;
use App\Models\AdobeProductAttributeOptionLineage;
use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetMembership;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaFieldClassification;
use Illuminate\Support\Collection;

final class AdobeProductExportPersistedMetadataReader
{
    /**
     * Build runtime metadata only from the reconciled Adobe/schema catalogue.
     *
     * @param  list<int>  $attributeSetIds
     * @param  list<string>  $relevantAttributeCodes
     */
    public function readForAttributeSets(
        string $workspaceId,
        string $connectorAccountId,
        array $attributeSetIds,
        array $relevantAttributeCodes = [],
        ?int $fallbackAttributeSetId = null,
    ): AdobeProductExportExecutionMetadata {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereKey($connectorAccountId)
            ->firstOrFail();

        $requestedIds = $this->normalizeAttributeSetIds($attributeSetIds);

        $allCurrentSets = AdobeProductAttributeSet::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->whereNull('missing_since')
            ->orderBy('provider_attribute_set_id')
            ->get();

        $attributeSets = $allCurrentSets
            ->map(static fn (AdobeProductAttributeSet $set): array => [
                'attribute_set_id' => (int) $set->provider_attribute_set_id,
                'attribute_set_name' => (string) $set->name,
            ])
            ->values()
            ->all();

        if ($requestedIds === []) {
            $selectedAttributeSetId = $fallbackAttributeSetId !== null && $fallbackAttributeSetId > 0
                ? $fallbackAttributeSetId
                : (int) ($attributeSets[0]['attribute_set_id'] ?? 0);

            return new AdobeProductExportExecutionMetadata(
                selectedAttributeSetId: $selectedAttributeSetId,
                attributeSets: $attributeSets,
                attributes: [],
                attributesByAttributeSetId: [],
            );
        }

        $requestedSets = $allCurrentSets
            ->filter(static fn (AdobeProductAttributeSet $set): bool => in_array(
                (int) $set->provider_attribute_set_id,
                $requestedIds,
                true,
            ))
            ->values();

        $attributesByAttributeSetId = $this->attributesBySet(
            $workspaceId,
            $connectorAccountId,
            $account->store_code,
            $requestedSets,
            $this->normalizeRelevantAttributeCodes($relevantAttributeCodes),
        );

        $selectedAttributeSetId = $fallbackAttributeSetId !== null
            && in_array($fallbackAttributeSetId, $requestedIds, true)
            ? $fallbackAttributeSetId
            : $requestedIds[0];

        return new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: $selectedAttributeSetId,
            attributeSets: $attributeSets,
            attributes: $attributesByAttributeSetId[$selectedAttributeSetId] ?? [],
            attributesByAttributeSetId: $attributesByAttributeSetId,
        );
    }

    /**
     * @param  Collection<int, AdobeProductAttributeSet>  $sets
     * @param  list<string>  $relevantAttributeCodes
     * @return array<int, array<string, AdobeAttributeMetadata>>
     */
    private function attributesBySet(
        string $workspaceId,
        string $connectorAccountId,
        ?string $storeCode,
        Collection $sets,
        array $relevantAttributeCodes,
    ): array {
        if ($sets->isEmpty()) {
            return [];
        }

        $setIds = $sets->pluck('id')->all();
        $providerSetById = $sets->mapWithKeys(
            static fn (AdobeProductAttributeSet $set): array => [
                (string) $set->id => (int) $set->provider_attribute_set_id,
            ],
        );

        $memberships = AdobeProductAttributeSetMembership::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->whereIn('adobe_product_attribute_set_id', $setIds)
            ->whereNull('missing_since')
            ->orderBy('adobe_product_attribute_set_id')
            ->orderBy('adobe_product_attribute_lineage_id')
            ->get();

        $lineageIds = $memberships
            ->pluck('adobe_product_attribute_lineage_id')
            ->unique()
            ->values()
            ->all();

        if ($lineageIds === []) {
            return [];
        }

        $lineagesQuery = AdobeProductAttributeLineage::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->whereIn('id', $lineageIds)
            ->whereNull('missing_since');

        if ($relevantAttributeCodes !== []) {
            $lineagesQuery->whereIn('last_external_field_key', $relevantAttributeCodes);
        }

        $lineages = $lineagesQuery->get()->keyBy('id');

        if ($lineages->isEmpty()) {
            return [];
        }

        $activeLineageIds = $lineages->keys()->all();
        $externalKeys = $lineages
            ->pluck('last_external_field_key')
            ->filter(static fn ($value): bool => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();
        $sourceIds = $sets
            ->pluck('connector_schema_source_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $classifications = ConnectorSchemaFieldClassification::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->when(
                $sourceIds !== [],
                static fn ($query) => $query->whereIn('connector_schema_source_id', $sourceIds),
            )
            ->whereIn('external_field_key', $externalKeys)
            ->with('latestSnapshotField')
            ->get()
            ->keyBy(static fn (ConnectorSchemaFieldClassification $classification): string => $classification->connector_schema_source_id.':'.$classification->external_field_key);

        $optionsByLineage = AdobeProductAttributeOptionLineage::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->whereIn('adobe_product_attribute_lineage_id', $activeLineageIds)
            ->whereNull('missing_since')
            ->orderBy('provider_option_id')
            ->get()
            ->groupBy('adobe_product_attribute_lineage_id');

        $result = [];

        foreach ($memberships as $membership) {
            $lineage = $lineages->get($membership->adobe_product_attribute_lineage_id);

            if (! $lineage instanceof AdobeProductAttributeLineage) {
                continue;
            }

            $providerSetId = $providerSetById->get((string) $membership->adobe_product_attribute_set_id);

            if (! is_int($providerSetId)) {
                continue;
            }

            $classification = $classifications->get(
                $lineage->connector_schema_source_id.':'.$lineage->last_external_field_key,
            );
            $field = $classification?->latestSnapshotField;

            if ($field === null) {
                continue;
            }

            $behavior = is_array($classification->behavior_signature)
                ? $classification->behavior_signature
                : [];
            $payload = is_object($field->normalized_payload)
                ? (array) $field->normalized_payload
                : (is_array($field->normalized_payload) ? $field->normalized_payload : []);
            $providerMetadata = $payload['provider_metadata'] ?? [];
            $providerMetadata = is_object($providerMetadata)
                ? (array) $providerMetadata
                : (is_array($providerMetadata) ? $providerMetadata : []);

            $frontendInput = $this->firstString(
                $behavior['frontend_input'] ?? null,
                $providerMetadata['frontend_input'] ?? null,
            );
            $scope = $this->firstString(
                $field->external_scope,
                $behavior['scope'] ?? null,
                $providerMetadata['scope'] ?? null,
            );

            if ($frontendInput === null || $scope === null) {
                // The reconciled catalogue is incomplete for runtime semantics; fail closed by
                // omitting this attribute so the semantic planner blocks its mapped use.
                continue;
            }

            $options = [];

            foreach ($optionsByLineage->get($lineage->id, collect()) as $option) {
                $value = trim((string) $option->provider_option_id);

                if ($value === '') {
                    continue;
                }

                $options[$value] = $this->optionLabel($option, $storeCode) ?? $value;
            }

            $result[$providerSetId] ??= [];
            $result[$providerSetId][(string) $lineage->last_external_field_key] = new AdobeAttributeMetadata(
                attributeId: (int) $lineage->provider_attribute_id,
                code: (string) $lineage->last_external_field_key,
                frontendInput: $frontendInput,
                scope: $scope,
                options: $options,
                defaultFrontendLabel: is_string($field->external_label) ? $field->external_label : null,
                isRequired: is_bool($field->is_required) ? $field->is_required : null,
                defaultValue: $providerMetadata['default_value'] ?? null,
                applyTo: $this->normalizeApplyTo($providerMetadata['apply_to'] ?? null),
            );
        }

        foreach ($sets as $set) {
            $result[(int) $set->provider_attribute_set_id] ??= [];
        }

        return $result;
    }

    /**
     * @param  list<int>  $attributeSetIds
     * @return list<int>
     */
    private function normalizeAttributeSetIds(array $attributeSetIds): array
    {
        $normalized = [];

        foreach ($attributeSetIds as $attributeSetId) {
            if (is_int($attributeSetId) && $attributeSetId > 0) {
                $normalized[$attributeSetId] = true;
            }
        }

        $ids = array_keys($normalized);
        sort($ids, SORT_NUMERIC);

        return array_values($ids);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function normalizeRelevantAttributeCodes(array $codes): array
    {
        $normalized = [];

        foreach ($codes as $code) {
            if (! is_string($code)) {
                continue;
            }

            $code = trim($code);

            if ($code !== '') {
                $normalized[] = $code;
            }
        }

        $values = array_values(array_unique($normalized, SORT_STRING));
        sort($values, SORT_STRING);

        return $values;
    }

    /** @return list<string> */
    private function normalizeApplyTo(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function firstString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function optionLabel(AdobeProductAttributeOptionLineage $option, ?string $storeCode): ?string
    {
        $labels = is_array($option->labels_by_store) ? $option->labels_by_store : [];

        if (is_string($storeCode) && $storeCode !== '') {
            $storeLabel = $labels[$storeCode] ?? null;

            if (is_string($storeLabel) && $storeLabel !== '') {
                return $storeLabel;
            }
        }

        if (is_string($option->default_label) && $option->default_label !== '') {
            return $option->default_label;
        }

        foreach ($labels as $label) {
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        return null;
    }
}
