<?php

namespace App\Services\Sync;

use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Enums\SyncDataDomain;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Services\Connectors\AuthoritativeConnectorSchemaSnapshotResolver;
use App\Support\Sync\Exceptions\FieldMappingProjectionInvariantException;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\FieldMappingReadModel\DiscoveredExternalFieldChoice;
use App\Support\Sync\FieldMappingReadModel\FieldMappingInternalRow;
use App\Support\Sync\FieldMappingReadModel\FieldMappingReadModel;
use App\Support\Workspace\WorkspaceOrGlobalScope;
use Illuminate\Support\Collection;

final class FieldMappingReadModelProjector
{
    public function __construct(
        private readonly AuthoritativeConnectorSchemaSnapshotResolver $snapshotResolver,
        private readonly CanonicalFieldMappingSuggestionProvider $suggestionProvider,
    ) {}

    public function project(
        ConnectorAccount $account,
        string $syncConfigurationId,
    ): FieldMappingReadModel {
        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('id', $syncConfigurationId)
            ->first();

        if ($configuration === null) {
            throw SyncConfigurationNotFoundException::forId($syncConfigurationId);
        }

        $account->loadMissing('connectorDefinition');

        $snapshot = $this->snapshotResolver->resolveSnapshot($account);
        $discoveryAvailable = $snapshot !== null;

        /** @var array<string, ConnectorSchemaSnapshotField> $snapshotFieldsByKey */
        $snapshotFieldsByKey = [];

        if ($snapshot !== null) {
            foreach (
                ConnectorSchemaSnapshotField::withoutWorkspaceScope()
                    ->where('workspace_id', $account->workspace_id)
                    ->where('snapshot_id', $snapshot->id)
                    ->orderBy('sort_order')
                    ->orderBy('external_field_key')
                    ->get() as $field
            ) {
                $snapshotFieldsByKey[$field->external_field_key] = $field;
            }
        }

        $snapshotExternalFieldKeys = array_fill_keys(array_keys($snapshotFieldsByKey), true);

        if ($configuration->data_domain !== SyncDataDomain::Products) {
            return new FieldMappingReadModel(
                syncConfigurationId: $configuration->id,
                discoveryAvailable: $discoveryAvailable,
                internalRows: [],
                discoveredExternalChoices: $this->buildDiscoveredExternalChoices($snapshotFieldsByKey),
            );
        }

        $existingMappings = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->get();

        $mappingByBindingId = $existingMappings->keyBy('field_binding_id');
        $reservedBindingIds = array_fill_keys($mappingByBindingId->keys()->all(), true);
        $reservedExternalKeys = array_fill_keys(
            $existingMappings->pluck('external_field_key')->all(),
            true,
        );

        $eligibleBindingIds = $this->eligibleManualBindingIds($configuration->workspace_id);
        $mappedBindingIds = $mappingByBindingId->keys()->all();
        $rowBindingIds = array_values(array_unique(array_merge($eligibleBindingIds, $mappedBindingIds)));

        $bindings = $this->loadBindingsWithDefinitions($rowBindingIds);

        $this->assertPersistedMappingsBelongToWorkspace(
            $configuration->workspace_id,
            $bindings,
            $mappingByBindingId,
        );

        $suggestions = $discoveryAvailable
            ? $this->suggestionProvider->suggest(
                $configuration->workspace_id,
                $account->connectorDefinition->code,
                $snapshotExternalFieldKeys,
                $reservedBindingIds,
                $reservedExternalKeys,
            )
            : [];

        $internalRows = $this->buildInternalRows(
            $bindings,
            $mappingByBindingId,
            $suggestions,
            $discoveryAvailable,
            $snapshotExternalFieldKeys,
            $configuration->workspace_id,
        );

        return new FieldMappingReadModel(
            syncConfigurationId: $configuration->id,
            discoveryAvailable: $discoveryAvailable,
            internalRows: $internalRows,
            discoveredExternalChoices: $this->buildDiscoveredExternalChoices($snapshotFieldsByKey),
        );
    }

    /**
     * @return list<string>
     */
    private function eligibleManualBindingIds(string $workspaceId): array
    {
        return FieldBinding::withoutWorkspaceScope()
            ->where(function ($query) use ($workspaceId): void {
                $query->whereNull('workspace_id')
                    ->orWhere('workspace_id', $workspaceId);
            })
            ->where('status', AttributeStatus::Active)
            ->whereIn('object_type', [FieldObjectType::Product, FieldObjectType::ProductVariant])
            ->whereHas('fieldDefinition', function ($query) use ($workspaceId): void {
                $query->withoutGlobalScope(WorkspaceOrGlobalScope::class)
                    ->where(function ($inner) use ($workspaceId): void {
                        $inner->whereNull('workspace_id')
                            ->orWhere('workspace_id', $workspaceId);
                    })
                    ->where('status', AttributeStatus::Active);
            })
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<string>  $bindingIds
     * @return list<FieldBinding>
     */
    private function loadBindingsWithDefinitions(array $bindingIds): array
    {
        if ($bindingIds === []) {
            return [];
        }

        return FieldBinding::withoutWorkspaceScope()
            ->whereIn('id', $bindingIds)
            ->with(['fieldDefinition' => function ($query): void {
                $query->withoutGlobalScope(WorkspaceOrGlobalScope::class);
            }])
            ->get()
            ->all();
    }

    /**
     * @param  list<FieldBinding>  $bindings
     * @param  Collection<string, FieldMapping>  $mappingByBindingId
     */
    private function assertPersistedMappingsBelongToWorkspace(
        string $workspaceId,
        array $bindings,
        Collection $mappingByBindingId,
    ): void {
        foreach ($bindings as $binding) {
            if (! $mappingByBindingId->has($binding->id)) {
                continue;
            }

            $definition = $binding->fieldDefinition;

            if (
                $definition === null
                || ! $this->bindingBelongsToWorkspace($binding, $workspaceId)
                || ! $this->definitionBelongsToWorkspace($definition, $workspaceId)
            ) {
                throw FieldMappingProjectionInvariantException::invalidPersistedMapping();
            }
        }
    }

    private function bindingBelongsToWorkspace(FieldBinding $binding, string $workspaceId): bool
    {
        return $binding->workspace_id === null || $binding->workspace_id === $workspaceId;
    }

    private function definitionBelongsToWorkspace(FieldDefinition $definition, string $workspaceId): bool
    {
        return $definition->workspace_id === null || $definition->workspace_id === $workspaceId;
    }

    /**
     * @param  list<FieldBinding>  $bindings
     * @param  Collection<string, FieldMapping>  $mappingByBindingId
     * @param  array<string, string>  $suggestions
     * @param  array<string, true>  $snapshotExternalFieldKeys
     * @return list<FieldMappingInternalRow>
     */
    private function buildInternalRows(
        array $bindings,
        $mappingByBindingId,
        array $suggestions,
        bool $discoveryAvailable,
        array $snapshotExternalFieldKeys,
        string $workspaceId,
    ): array {
        $rows = [];

        foreach ($bindings as $binding) {
            $definition = $binding->fieldDefinition;

            if ($definition === null) {
                continue;
            }

            if (
                ! $this->bindingBelongsToWorkspace($binding, $workspaceId)
                || ! $this->definitionBelongsToWorkspace($definition, $workspaceId)
            ) {
                continue;
            }

            $mapping = $mappingByBindingId->get($binding->id);
            $existingExternalKey = $mapping?->external_field_key;
            $suggestedExternalKey = $existingExternalKey === null
                ? ($suggestions[$binding->id] ?? null)
                : null;

            $needsAttention = $binding->status !== AttributeStatus::Active
                || $definition->status !== AttributeStatus::Active;

            if (
                $discoveryAvailable
                && $existingExternalKey !== null
                && ! isset($snapshotExternalFieldKeys[$existingExternalKey])
            ) {
                $needsAttention = true;
            }

            $rows[] = new FieldMappingInternalRow(
                fieldBindingId: $binding->id,
                internalFieldCode: $definition->code,
                objectType: $binding->object_type,
                label: $definition->localizedLabel(),
                existingExternalFieldKey: $existingExternalKey,
                suggestedExternalFieldKey: $suggestedExternalKey,
                needsAttention: $needsAttention,
            );
        }

        $sortOrdersByBindingId = [];

        foreach ($bindings as $binding) {
            $sortOrdersByBindingId[$binding->id] = $binding->sort_order;
        }

        usort(
            $rows,
            fn (FieldMappingInternalRow $left, FieldMappingInternalRow $right): int => $this->compareInternalRows(
                $sortOrdersByBindingId,
                $left,
                $right,
            ),
        );

        return $rows;
    }

    /**
     * @param  array<string, int>  $sortOrdersByBindingId
     */
    private function compareInternalRows(
        array $sortOrdersByBindingId,
        FieldMappingInternalRow $left,
        FieldMappingInternalRow $right,
    ): int {
        $leftSortOrder = $sortOrdersByBindingId[$left->fieldBindingId] ?? 0;
        $rightSortOrder = $sortOrdersByBindingId[$right->fieldBindingId] ?? 0;

        if ($leftSortOrder !== $rightSortOrder) {
            return $leftSortOrder <=> $rightSortOrder;
        }

        $objectTypeOrder = [
            FieldObjectType::Product->value => 0,
            FieldObjectType::ProductVariant->value => 1,
        ];

        $leftObjectOrder = $objectTypeOrder[$left->objectType->value] ?? 99;
        $rightObjectOrder = $objectTypeOrder[$right->objectType->value] ?? 99;

        if ($leftObjectOrder !== $rightObjectOrder) {
            return $leftObjectOrder <=> $rightObjectOrder;
        }

        if ($left->internalFieldCode !== $right->internalFieldCode) {
            return $left->internalFieldCode <=> $right->internalFieldCode;
        }

        return $left->fieldBindingId <=> $right->fieldBindingId;
    }

    /**
     * @param  array<string, ConnectorSchemaSnapshotField>  $snapshotFieldsByKey
     * @return list<DiscoveredExternalFieldChoice>
     */
    private function buildDiscoveredExternalChoices(array $snapshotFieldsByKey): array
    {
        $choices = [];

        foreach ($snapshotFieldsByKey as $field) {
            $choices[] = new DiscoveredExternalFieldChoice(
                externalFieldKey: $field->external_field_key,
                externalLabel: $field->external_label,
                normalizedDataType: $field->normalized_data_type,
                isRequired: $field->is_required,
                isMultiValue: $field->is_multi_value,
                isLocalizable: $field->is_localizable,
            );
        }

        return $choices;
    }
}
