<?php

namespace Tests\Concerns;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorDiscoveryRunTrigger;
use App\Enums\FieldObjectType;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\SyncConfiguration;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Services\Sync\CreateSyncConfigurationInput;
use App\Services\Sync\SyncConfigurationService;
use App\Support\Sync\SyncExternalContext;
use Database\Seeders\FieldDefinitionSeeder;
use Illuminate\Support\Str;

trait InteractsWithFieldMappingFixtures
{
    protected function createProductsSyncConfiguration(ConnectorAccount $account): SyncConfiguration
    {
        return app(SyncConfigurationService::class)->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Import],
            operationalState: SyncConfigurationOperationalState::Enabled,
        ));
    }

    protected function productBinding(string $code = 'name'): FieldBinding
    {
        return FieldBinding::withoutWorkspaceScope()
            ->whereHas('fieldDefinition', fn ($query) => $query->where('code', $code))
            ->where('object_type', FieldObjectType::Product)
            ->firstOrFail();
    }

    protected function productVariantBinding(string $code = 'sku'): FieldBinding
    {
        return FieldBinding::withoutWorkspaceScope()
            ->whereHas('fieldDefinition', fn ($query) => $query->where('code', $code))
            ->where('object_type', FieldObjectType::ProductVariant)
            ->firstOrFail();
    }

    protected function customerBinding(): FieldBinding
    {
        return FieldBinding::withoutWorkspaceScope()
            ->where('object_type', FieldObjectType::Customer)
            ->firstOrFail();
    }

    protected function createWorkspaceScopedProductBinding(Workspace $workspace): FieldBinding
    {
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'ws_field_'.Str::random(6),
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Тест'],
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        return FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 999,
            'status' => AttributeStatus::Active,
        ]);
    }

    protected function createForeignWorkspaceBinding(): FieldBinding
    {
        $workspace = Workspace::query()->create([
            'name' => 'Foreign '.Str::random(4),
            'is_default' => false,
        ]);

        return $this->createWorkspaceScopedProductBinding($workspace);
    }

    protected function publishAuthoritativeSnapshot(
        ConnectorAccount $account,
        array $externalFieldKeys,
        ?\DateTimeInterface $createdAt = null,
    ): ConnectorSchemaSnapshot {
        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);

        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => ConnectorDiscoveryRunTrigger::Manual,
            'status' => ConnectorDiscoveryRunStatus::Succeeded,
            'execution_attempts' => 1,
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => $createdAt ?? now(),
        ]);

        $snapshot = ConnectorSchemaSnapshot::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'discovery_run_id' => $run->id,
            'schema_version' => '1.0',
            'field_count' => count($externalFieldKeys),
            'canonical_hash' => hash('sha256', Str::uuid()->toString()),
            'captured_at' => now(),
            'created_at' => $createdAt ?? now(),
        ]);

        $run->update(['snapshot_id' => $snapshot->id]);

        foreach ($externalFieldKeys as $index => $key) {
            ConnectorSchemaSnapshotField::withoutWorkspaceScope()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $account->workspace_id,
                'snapshot_id' => $snapshot->id,
                'external_field_key' => $key,
                'external_label' => $key,
                'normalized_data_type' => 'string',
                'is_required' => false,
                'is_multi_value' => false,
                'is_localizable' => false,
                'external_scope' => 'global',
                'normalized_payload' => ['source' => 'test'],
                'canonical_hash' => hash('sha256', $key),
                'sort_order' => $index + 1,
            ]);
        }

        return $snapshot;
    }

    /**
     * @param  array<string, list<array{value: string, label: string}>>  $fieldsWithOptions
     */
    protected function publishAuthoritativeSnapshotWithOptions(
        ConnectorAccount $account,
        array $fieldsWithOptions,
        ?\DateTimeInterface $createdAt = null,
    ): ConnectorSchemaSnapshot {
        $snapshot = $this->publishAuthoritativeSnapshot($account, array_keys($fieldsWithOptions), $createdAt);

        foreach ($fieldsWithOptions as $externalFieldKey => $options) {
            $optionObjects = [];

            foreach ($options as $option) {
                $optionObjects[] = (object) [
                    'value' => $option['value'],
                    'label' => $option['label'],
                ];
            }

            ConnectorSchemaSnapshotField::withoutWorkspaceScope()
                ->where('snapshot_id', $snapshot->id)
                ->where('external_field_key', $externalFieldKey)
                ->first()
                ?->forceFill([
                    'normalized_data_type' => 'select',
                    'normalized_payload' => (object) ['options' => $optionObjects],
                ])
                ?->save();
        }

        return $snapshot;
    }

    protected function seedFieldDefinitions(): void
    {
        $this->seed(FieldDefinitionSeeder::class);
    }
}
