<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Services\Connectors\AuthoritativeConnectorSchemaSnapshotResolver;
use App\Services\Connectors\AuthoritativeExternalOptionChoiceResolver;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\FieldOptionMappingReadModel\FieldOptionMappingNormalRow;
use App\Support\Sync\FieldOptionMappingReadModel\FieldOptionMappingReadModel;
use App\Support\Sync\FieldOptionMappingReadModel\FieldOptionMappingRowState;
use App\Support\Sync\FieldOptionMappingReadModel\FieldOptionMappingStaleCorrespondenceRow;

final class FieldOptionMappingReadModelProjector
{
    public function __construct(
        private readonly FieldOptionMappingEligibilityResolver $eligibilityResolver,
        private readonly FieldDefinitionOptionCatalog $optionCatalog,
        private readonly AuthoritativeExternalOptionChoiceResolver $externalOptionChoiceResolver,
        private readonly AuthoritativeConnectorSchemaSnapshotResolver $snapshotResolver,
    ) {}

    public function project(
        ConnectorAccount $account,
        string $syncConfigurationId,
        string $fieldMappingId,
    ): FieldOptionMappingReadModel {
        $account->loadMissing('connectorDefinition');

        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('id', $syncConfigurationId)
            ->first();

        if ($configuration === null) {
            throw SyncConfigurationNotFoundException::forId($syncConfigurationId);
        }

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('id', $fieldMappingId)
            ->with(['fieldBinding.fieldDefinition', 'optionMappings'])
            ->first();

        if ($mapping === null) {
            throw SyncConfigurationNotFoundException::forId($syncConfigurationId);
        }

        $binding = $mapping->fieldBinding;
        $definition = $binding?->fieldDefinition;
        $eligible = $this->eligibilityResolver->isEligibleMapping($mapping);
        $externalFieldKey = (string) $mapping->external_field_key;
        $externalChoices = $eligible
            ? $this->externalOptionChoiceResolver->resolveChoices($account, $externalFieldKey)
            : [];
        $externalFieldPresent = $this->externalOptionChoiceResolver->externalFieldPresent($account, $externalFieldKey);
        $externalChoicesResolvable = $externalFieldPresent && $externalChoices !== [];

        $externalLabelsByValue = [];

        foreach ($externalChoices as $choice) {
            $externalLabelsByValue[$choice->value] = $choice->presentationLabel();
        }

        $currentOptionCodes = $definition !== null
            ? $this->optionCatalog->currentOptionCodes($definition)
            : [];
        $currentOptionCodeSet = array_fill_keys($currentOptionCodes, true);

        $persistedByInternalKey = [];

        foreach ($mapping->optionMappings as $optionMapping) {
            $persistedByInternalKey[$optionMapping->internal_option_key] = $optionMapping;
        }

        $normalRows = [];

        foreach ($currentOptionCodes as $internalOptionKey) {
            $persisted = $persistedByInternalKey[$internalOptionKey] ?? null;
            $internalLabel = $definition !== null
                ? $this->optionCatalog->localizedOptionLabel($definition, $internalOptionKey)
                : $internalOptionKey;

            if ($persisted === null) {
                $normalRows[] = new FieldOptionMappingNormalRow(
                    internalOptionKey: $internalOptionKey,
                    internalLabel: $internalLabel,
                    externalOptionValue: null,
                    externalLabel: null,
                    semanticState: FieldOptionMappingRowState::UNMAPPED,
                    existingExternalOptionValue: null,
                );

                continue;
            }

            $externalValue = $persisted->external_option_value;
            $externalLabel = $externalLabelsByValue[$externalValue] ?? null;
            $semanticState = isset($externalLabelsByValue[$externalValue])
                ? FieldOptionMappingRowState::MAPPED
                : FieldOptionMappingRowState::EXTERNAL_VALUE_UNAVAILABLE;

            $normalRows[] = new FieldOptionMappingNormalRow(
                internalOptionKey: $internalOptionKey,
                internalLabel: $internalLabel,
                externalOptionValue: $externalValue,
                externalLabel: $externalLabel,
                semanticState: $semanticState,
                existingExternalOptionValue: $externalValue,
            );
        }

        $staleRows = [];

        foreach ($mapping->optionMappings as $optionMapping) {
            if (isset($currentOptionCodeSet[$optionMapping->internal_option_key])) {
                continue;
            }

            $externalValue = $optionMapping->external_option_value;
            $externalLabel = $externalLabelsByValue[$externalValue] ?? null;

            $staleRows[] = new FieldOptionMappingStaleCorrespondenceRow(
                fieldOptionMappingId: $optionMapping->id,
                externalOptionValue: $externalValue,
                externalLabel: $externalLabel,
            );
        }

        return new FieldOptionMappingReadModel(
            fieldMappingId: $mapping->id,
            fieldBindingId: $mapping->field_binding_id,
            internalFieldLabel: $definition?->localizedLabel() ?? '',
            externalFieldKey: $externalFieldKey,
            externalFieldLabel: $this->resolveExternalFieldLabel($account, $externalFieldKey),
            platformName: $account->connectorDefinition->name,
            accountName: $account->name,
            externalChoicesResolvable: $externalChoicesResolvable,
            eligible: $eligible,
            normalRows: $normalRows,
            staleCorrespondenceRows: $staleRows,
            externalChoices: $externalChoices,
        );
    }

    private function resolveExternalFieldLabel(ConnectorAccount $account, string $externalFieldKey): string
    {
        $snapshot = $this->snapshotResolver->resolveSnapshot($account);

        if ($snapshot === null) {
            return $externalFieldKey;
        }

        $field = ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('snapshot_id', $snapshot->id)
            ->where('external_field_key', $externalFieldKey)
            ->first();

        if ($field === null) {
            return $externalFieldKey;
        }

        return $field->external_label !== '' ? $field->external_label : $externalFieldKey;
    }
}
