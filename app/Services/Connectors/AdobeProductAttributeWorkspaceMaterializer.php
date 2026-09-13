<?php

namespace App\Services\Connectors;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\ConnectorSchemaFieldDisposition;
use App\Enums\FieldObjectType;
use App\Models\AdobeProductAttributeLineage;
use App\Models\AdobeProductAttributeMaterialization;
use App\Models\AdobeProductAttributeOptionLineage;
use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetMembership;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaFieldClassification;
use App\Models\ConnectorSchemaSource;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\FieldOptionMappingMutationService;
use App\Services\Sync\SyncConfigurationLookupService;
use App\Support\Connectors\AdobePaaS\AdobeProductAttributeMaterializationResult;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionConfiguration;
use Illuminate\Support\Facades\DB;

final class AdobeProductAttributeWorkspaceMaterializer
{
    public function __construct(
        private readonly ConnectorDiscoverySourceResolver $sourceResolver,
        private readonly AdobeProductAttributeEntityEvidenceResolver $entityEvidenceResolver,
        private readonly SyncConfigurationLookupService $configurationLookup,
        private readonly FieldMappingMutationService $fieldMappingMutationService,
        private readonly FieldOptionMappingMutationService $optionMappingMutationService,
    ) {}

    public function materialize(string $workspaceId, string $connectorAccountId): AdobeProductAttributeMaterializationResult
    {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereKey($connectorAccountId)
            ->firstOrFail();
        $source = $this->sourceResolver->resolve($account);
        $configuration = $this->configurationLookup->findProductsDefaultContext($account);

        if (! $configuration instanceof SyncConfiguration) {
            return new AdobeProductAttributeMaterializationResult(0, 0, 0, 0, 0, 0);
        }

        $exportConfiguration = AdobeProductExportExecutionConfiguration::fromPayload(
            $configuration->connectorExecutionConfiguration()->payload(),
        );
        $targetSignature = $this->targetSignature($account);
        $expectedConfigurationRevision = $configuration->configuration_revision;
        $evidence = $this->entityEvidenceResolver->resolve($account);

        $core = DB::transaction(function () use (
            $workspaceId,
            $connectorAccountId,
            $source,
            $configuration,
            $exportConfiguration,
            $targetSignature,
            $expectedConfigurationRevision,
            $evidence,
        ): array {
            $lockedAccount = ConnectorAccount::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->whereKey($connectorAccountId)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedSource = ConnectorSchemaSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $lockedConfiguration = SyncConfiguration::withoutWorkspaceScope()
                ->whereKey($configuration->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->targetSignature($lockedAccount) !== $targetSignature
                || $lockedSource->endpoint_path !== $source->endpoint_path
                || ! $this->sourceResolver->reverify($lockedAccount, $lockedSource)
                || $lockedConfiguration->configuration_revision !== $expectedConfigurationRevision) {
                throw new \RuntimeException('Adobe custom attribute materialization context changed during evidence collection.');
            }

            return $this->materializeCore(
                $lockedAccount,
                $lockedSource,
                $lockedConfiguration,
                $exportConfiguration->attributeSetId,
                $evidence,
            );
        }, 3);

        $mappingCount = 0;
        $optionMappingCount = 0;

        foreach ($core['targets'] as $target) {
            $existingMapping = FieldMapping::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configuration->id)
                ->where('field_binding_id', $target['field_binding_id'])
                ->first();

            if ($existingMapping === null) {
                $this->fieldMappingMutationService->confirm(
                    $account,
                    $configuration->id,
                    $target['field_binding_id'],
                    $target['external_field_key'],
                );
            } elseif ((string) $existingMapping->external_field_key !== $target['external_field_key']) {
                $this->fieldMappingMutationService->replace(
                    $account,
                    $configuration->id,
                    $target['field_binding_id'],
                    (string) $existingMapping->external_field_key,
                    newExternalFieldKey: $target['external_field_key'],
                );
            }

            $mapping = FieldMapping::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configuration->id)
                ->where('field_binding_id', $target['field_binding_id'])
                ->where('external_field_key', $target['external_field_key'])
                ->firstOrFail();
            $mappingCount++;

            $this->optionMappingMutationService->replaceAuthoritativeSet(
                $account,
                $configuration->id,
                $mapping->id,
                $target['option_pairs'],
            );
            $optionMappingCount += count($target['option_pairs']);
        }

        return new AdobeProductAttributeMaterializationResult(
            eligibleFields: $core['eligible_fields'],
            definitions: $core['definitions'],
            bindings: $core['bindings'],
            fieldMappings: $mappingCount,
            optionMappings: $optionMappingCount,
            deferredFields: $core['deferred_fields'],
        );
    }

    /**
     * @param  array<string, list<FieldObjectType>>  $evidence
     * @return array{eligible_fields:int,definitions:int,bindings:int,deferred_fields:int,targets:list<array{field_binding_id:string,external_field_key:string,option_pairs:list<array{internal_option_key:string,external_option_value:string}>}>}
     */
    private function materializeCore(
        ConnectorAccount $account,
        ConnectorSchemaSource $source,
        SyncConfiguration $configuration,
        int $providerAttributeSetId,
        array $evidence,
    ): array {
        $scope = [
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
        ];
        $attributeSet = AdobeProductAttributeSet::withoutWorkspaceScope()
            ->where($scope)
            ->where('provider_attribute_set_id', $providerAttributeSetId)
            ->whereNull('missing_since')
            ->first();

        if (! $attributeSet instanceof AdobeProductAttributeSet) {
            throw new \RuntimeException('Configured Adobe Product Attribute Set is absent from the reconciled provider structure.');
        }

        $classifications = ConnectorSchemaFieldClassification::withoutWorkspaceScope()
            ->where($scope)
            ->where('disposition', ConnectorSchemaFieldDisposition::WorkspaceCustom->value)
            ->with('latestSnapshotField')
            ->get();
        $lineages = AdobeProductAttributeLineage::withoutWorkspaceScope()
            ->where($scope)
            ->whereNull('missing_since')
            ->get()
            ->keyBy('current_external_field_key');
        $memberships = AdobeProductAttributeSetMembership::withoutWorkspaceScope()
            ->where($scope)
            ->where('adobe_product_attribute_set_id', $attributeSet->id)
            ->whereNull('missing_since')
            ->get()
            ->keyBy('adobe_product_attribute_lineage_id');
        $materializations = AdobeProductAttributeMaterialization::withoutWorkspaceScope()
            ->where($scope)
            ->get()
            ->keyBy('adobe_product_attribute_lineage_id');

        $targets = [];
        $definitionCount = 0;
        $bindingCount = 0;
        $eligibleCount = 0;
        $deferredCount = 0;

        foreach ($classifications as $classification) {
            $field = $classification->latestSnapshotField;
            $externalFieldKey = (string) $classification->external_field_key;
            $lineage = $lineages->get($externalFieldKey);
            $objectTypes = $evidence[$externalFieldKey] ?? [];

            if ($field === null
                || $field->normalized_data_type !== AttributeDataType::Select->value
                || $field->is_multi_value
                || $field->is_localizable
                || ! $lineage instanceof AdobeProductAttributeLineage
                || ! $memberships->has($lineage->id)
                || count($objectTypes) !== 1) {
                $deferredCount++;

                continue;
            }

            $objectType = $objectTypes[0];
            if (! in_array($objectType, [FieldObjectType::Product, FieldObjectType::ProductVariant], true)) {
                $deferredCount++;

                continue;
            }

            $eligibleCount++;
            $optionLineages = AdobeProductAttributeOptionLineage::withoutWorkspaceScope()
                ->where($scope)
                ->where('adobe_product_attribute_lineage_id', $lineage->id)
                ->whereNull('missing_since')
                ->orderBy('provider_option_id')
                ->get();
            $validationRules = ['options' => $optionLineages->map(fn (AdobeProductAttributeOptionLineage $option): array => [
                'code' => (string) $option->provider_option_id,
                'labels' => $this->optionLabels($option),
            ])->values()->all()];

            $materialization = $materializations->get($lineage->id);
            $definition = $materialization?->fieldDefinition;

            if (! $definition instanceof FieldDefinition) {
                $definition = FieldDefinition::withoutWorkspaceScope()->create([
                    'workspace_id' => $account->workspace_id,
                    'code' => $this->resolveInternalCode($account, $lineage),
                    'data_type' => AttributeDataType::Select,
                    'scope' => AttributeScope::WorkspaceCustom,
                    'localized_labels' => ['uk' => $field->external_label ?: $externalFieldKey],
                    'description' => null,
                    'validation_rules' => $validationRules,
                    'is_localizable' => false,
                    'is_multi_value' => false,
                    'status' => AttributeStatus::Active,
                ]);
                $materialization = AdobeProductAttributeMaterialization::withoutWorkspaceScope()->create([
                    ...$scope,
                    'adobe_product_attribute_lineage_id' => $lineage->id,
                    'field_definition_id' => $definition->id,
                    'materialized_at' => now(),
                ]);
                $materializations->put($lineage->id, $materialization);
                $definitionCount++;
            } elseif ($definition->status !== AttributeStatus::Active) {
                $deferredCount++;

                continue;
            } else {
                $definition->validation_rules = $validationRules;
                $definition->save();
            }

            $binding = FieldBinding::withoutWorkspaceScope()
                ->where('field_definition_id', $definition->id)
                ->where('object_type', $objectType)
                ->first();

            if (! $binding instanceof FieldBinding) {
                $binding = FieldBinding::withoutWorkspaceScope()->create([
                    'workspace_id' => $account->workspace_id,
                    'field_definition_id' => $definition->id,
                    'object_type' => $objectType,
                    'storage_type' => AttributeStorageType::Dynamic,
                    'storage_path' => null,
                    'field_group' => 'characteristics',
                    'is_required' => false,
                    'is_filterable' => false,
                    'is_sortable' => false,
                    'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                    'sort_order' => $field->sort_order ?? 1000,
                    'status' => AttributeStatus::Active,
                ]);
                $bindingCount++;
            } elseif ($binding->status !== AttributeStatus::Active || $binding->storage_type !== AttributeStorageType::Dynamic) {
                $deferredCount++;

                continue;
            }

            $targets[] = [
                'field_binding_id' => $binding->id,
                'external_field_key' => $externalFieldKey,
                'option_pairs' => $optionLineages->map(static fn (AdobeProductAttributeOptionLineage $option): array => [
                    'internal_option_key' => (string) $option->provider_option_id,
                    'external_option_value' => (string) $option->provider_option_id,
                ])->values()->all(),
            ];
        }

        return [
            'eligible_fields' => $eligibleCount,
            'definitions' => $definitionCount,
            'bindings' => $bindingCount,
            'deferred_fields' => $deferredCount,
            'targets' => $targets,
        ];
    }

    private function resolveInternalCode(ConnectorAccount $account, AdobeProductAttributeLineage $lineage): string
    {
        $externalCode = $lineage->last_external_field_key;
        $existing = FieldDefinition::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('code', $externalCode)
            ->first();

        if ($existing === null) {
            return $externalCode;
        }

        $suffix = '__adobe_'.substr(hash('sha256', $account->id.':'.$lineage->provider_attribute_id), 0, 12);
        $candidate = mb_substr($externalCode, 0, max(1, 255 - strlen($suffix))).$suffix;

        if (FieldDefinition::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('code', $candidate)
            ->exists()) {
            throw new \RuntimeException('Deterministic Adobe workspace field code is already occupied.');
        }

        return $candidate;
    }

    /** @return array<string, string> */
    private function optionLabels(AdobeProductAttributeOptionLineage $option): array
    {
        $labels = [];
        foreach ($option->labels_by_store ?? [] as $storeCode => $label) {
            if (is_string($storeCode) && preg_match('/^[a-z]{2}$/', $storeCode) === 1 && is_string($label) && $label !== '') {
                $labels[$storeCode] = $label;
            }
        }

        if ($labels === [] && is_string($option->default_label) && $option->default_label !== '') {
            $labels['uk'] = $option->default_label;
        }

        return $labels;
    }

    /** @return array<string, mixed> */
    private function targetSignature(ConnectorAccount $account): array
    {
        return [
            'connector_definition_id' => $account->connector_definition_id,
            'auth_profile' => $account->auth_profile,
            'base_url' => $account->base_url,
            'store_code' => $account->store_code,
            'tenant_context' => $account->tenant_context,
            'is_enabled' => $account->is_enabled,
            'credentials' => $account->credentials,
        ];
    }
}
