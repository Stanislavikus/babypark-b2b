<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Models\AdobeProductAttributeLineage;
use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetMembership;
use App\Models\ConnectorSchemaFieldClassification;
use App\Support\Connectors\AdobePaaS\AdobeAttributeMetadata;
use App\Support\Connectors\AdobePaaS\AdobeProductExportPersistedMetadataReader;

final class AdobeSimpleProductCreateAttributeValidator
{
    /** @var list<string> */
    private const CORE_FIELDS = [
        'attribute_set_id',
        'name',
        'price',
        'sku',
        'status',
        'type_id',
        'visibility',
    ];

    /** Magento-managed output fields that are not merchant-supplied on Product CREATE. */
    private const PROVIDER_MANAGED_FIELDS = [
        'created_at',
        'updated_at',
    ];

    public function __construct(
        private readonly AdobeProductExportPersistedMetadataReader $metadataReader,
    ) {}

    public function validate(
        string $workspaceId,
        string $connectorAccountId,
        AdobeProductDesiredState $desiredState,
    ): AdobeSimpleProductCreateValidationResult {
        $set = AdobeProductAttributeSet::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('provider_attribute_set_id', $desiredState->attributeSetId)
            ->whereNull('missing_since')
            ->first();

        if (! $set instanceof AdobeProductAttributeSet) {
            return AdobeSimpleProductCreateValidationResult::blocked('adobe_create_attribute_set_unavailable');
        }

        $membershipCount = AdobeProductAttributeSetMembership::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('adobe_product_attribute_set_id', $set->id)
            ->whereNull('missing_since')
            ->count();

        $metadata = $this->metadataReader->readForAttributeSets(
            $workspaceId,
            $connectorAccountId,
            [$desiredState->attributeSetId],
            [],
            $desiredState->attributeSetId,
        );

        if (! $metadata->hasAttributeSet($desiredState->attributeSetId)) {
            return AdobeSimpleProductCreateValidationResult::blocked('adobe_create_attribute_set_unavailable');
        }

        if ($membershipCount !== count($metadata->attributes)
            && $this->hasPotentialRequiredSimpleMetadataGap(
                $workspaceId,
                $connectorAccountId,
                $set->id,
                array_keys($metadata->attributes),
            )
        ) {
            return AdobeSimpleProductCreateValidationResult::blocked('adobe_create_attribute_metadata_incomplete');
        }

        $missing = [];
        $invalidOptions = [];

        foreach ($metadata->attributes as $code => $attribute) {
            if (
                ! $attribute instanceof AdobeAttributeMetadata
                || ! $this->appliesToSimple($attribute)
                || in_array($code, self::PROVIDER_MANAGED_FIELDS, true)
            ) {
                continue;
            }

            $value = $this->valueFor($code, $desiredState);

            if ($value !== null && $this->requiresOptions($attribute)) {
                if (! $this->optionsContain($attribute, $value)) {
                    $invalidOptions[] = $code;
                }
            }

            if ($attribute->isRequired !== true) {
                continue;
            }

            if ($this->hasValue($value)) {
                continue;
            }

            if ($this->hasValue($attribute->defaultValue)) {
                if ($this->requiresOptions($attribute)
                    && ! $this->optionsContain($attribute, $attribute->defaultValue)
                ) {
                    $invalidOptions[] = $code;
                }

                continue;
            }

            $missing[] = $code;
        }

        if ($invalidOptions !== []) {
            return AdobeSimpleProductCreateValidationResult::blocked(
                'adobe_create_attribute_option_invalid',
                $invalidOptions,
            );
        }

        if ($missing !== []) {
            return AdobeSimpleProductCreateValidationResult::blocked(
                'adobe_create_required_attributes_missing',
                $missing,
            );
        }

        return AdobeSimpleProductCreateValidationResult::ready();
    }

    /**
     * @param  list<string>  $knownCodes
     */
    private function hasPotentialRequiredSimpleMetadataGap(
        string $workspaceId,
        string $connectorAccountId,
        string $attributeSetId,
        array $knownCodes,
    ): bool {
        $known = array_fill_keys($knownCodes, true);

        $memberships = AdobeProductAttributeSetMembership::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('adobe_product_attribute_set_id', $attributeSetId)
            ->whereNull('missing_since')
            ->get();

        $lineageIds = $memberships
            ->pluck('adobe_product_attribute_lineage_id')
            ->unique()
            ->values()
            ->all();

        $lineages = AdobeProductAttributeLineage::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->whereIn('id', $lineageIds)
            ->whereNull('missing_since')
            ->get()
            ->keyBy('id');

        $sourceIds = $lineages
            ->pluck('connector_schema_source_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $externalKeys = $lineages
            ->pluck('last_external_field_key')
            ->filter(static fn ($value): bool => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();

        $classifications = ConnectorSchemaFieldClassification::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->when($sourceIds !== [], static fn ($query) => $query->whereIn('connector_schema_source_id', $sourceIds))
            ->whereIn('external_field_key', $externalKeys)
            ->with('latestSnapshotField')
            ->get()
            ->keyBy(static fn (ConnectorSchemaFieldClassification $classification): string => $classification->connector_schema_source_id.':'.$classification->external_field_key);

        foreach ($memberships as $membership) {
            $lineage = $lineages->get($membership->adobe_product_attribute_lineage_id);

            if (! $lineage instanceof AdobeProductAttributeLineage) {
                return true;
            }

            $code = (string) $lineage->last_external_field_key;

            if ($code === '' || isset($known[$code])) {
                continue;
            }

            $classification = $classifications->get(
                $lineage->connector_schema_source_id.':'.$code,
            );
            $behavior = is_array($classification?->behavior_signature)
                ? $classification->behavior_signature
                : [];
            $applyTo = $behavior['apply_to'] ?? [];
            $applyTo = is_array($applyTo)
                ? array_values(array_filter($applyTo, static fn ($value): bool => is_string($value) && $value !== ''))
                : [];

            if ($applyTo !== [] && ! in_array('simple', $applyTo, true)) {
                continue;
            }

            $fieldRequired = $classification?->latestSnapshotField?->is_required;
            $behaviorRequired = $behavior['required'] ?? null;
            $required = is_bool($fieldRequired)
                ? $fieldRequired
                : (is_bool($behaviorRequired) ? $behaviorRequired : null);

            if ($required === false) {
                continue;
            }

            // Required=true or unknown on a Simple-applicable membership must fail closed.
            return true;
        }

        return false;
    }

    private function appliesToSimple(AdobeAttributeMetadata $attribute): bool
    {
        return $attribute->applyTo === [] || in_array('simple', $attribute->applyTo, true);
    }

    private function valueFor(string $code, AdobeProductDesiredState $desiredState): mixed
    {
        if (in_array($code, self::CORE_FIELDS, true)) {
            return match ($code) {
                'sku' => $desiredState->sku,
                'name' => $desiredState->name,
                'attribute_set_id' => $desiredState->attributeSetId,
                'type_id' => $desiredState->typeId,
                'status' => $desiredState->status,
                'visibility' => $desiredState->visibility,
                'price' => $desiredState->price,
            };
        }

        return $desiredState->customAttributes[$code] ?? null;
    }

    private function hasValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return ! is_array($value) || $value !== [];
    }

    private function requiresOptions(AdobeAttributeMetadata $attribute): bool
    {
        return in_array($attribute->frontendInput, ['select', 'multiselect'], true);
    }

    private function optionsContain(AdobeAttributeMetadata $attribute, mixed $value): bool
    {
        if ($attribute->options === []) {
            return false;
        }

        $values = is_array($value)
            ? $value
            : ($attribute->frontendInput === 'multiselect'
                ? explode(',', (string) $value)
                : [$value]);

        foreach ($values as $item) {
            $normalized = trim((string) $item);

            if ($normalized === '' || ! array_key_exists($normalized, $attribute->options)) {
                return false;
            }
        }

        return true;
    }
}
