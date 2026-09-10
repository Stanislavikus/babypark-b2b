<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Models\FieldOptionMapping;
use App\Models\SyncConfiguration;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\FieldMappingRevisionEntry;
use App\Support\Sync\FieldOptionMappingRevisionEntry;
use App\Support\Sync\SyncConfigurationRevisionHasher;
use Illuminate\Support\Facades\DB;

final class SyncConfigurationMutationCoordinator
{
    public function __construct(
        private readonly SyncConfigurationRevisionHasher $revisionHasher,
    ) {}

    /**
     * @param  callable(SyncConfiguration): void  $mutator
     */
    public function mutateLocked(
        ConnectorAccount $account,
        string $syncConfigurationId,
        callable $mutator,
    ): SyncConfiguration {
        return DB::transaction(function () use ($account, $syncConfigurationId, $mutator): SyncConfiguration {
            $configuration = $this->lockConfiguration($account, $syncConfigurationId);

            $mutator($configuration);

            $this->refreshConfigurationRevision($configuration);

            return $configuration->refresh();
        });
    }

    public function lockConfiguration(ConnectorAccount $account, string $syncConfigurationId): SyncConfiguration
    {
        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('id', $syncConfigurationId)
            ->lockForUpdate()
            ->first();

        if ($configuration === null) {
            throw SyncConfigurationNotFoundException::forId($syncConfigurationId);
        }

        return $configuration;
    }

    public function refreshConfigurationRevision(SyncConfiguration $configuration): void
    {
        $configuration->configuration_revision = $this->revisionHasher->hash(
            $configuration->enabledOperationSet(),
            $configuration->operational_state,
            $this->effectiveMappingPayload($configuration),
            $configuration->connectorExecutionConfiguration(),
        );
        $configuration->save();
    }

    /**
     * @return list<FieldMappingRevisionEntry>
     */
    public function effectiveMappingPayload(SyncConfiguration $configuration): array
    {
        return FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->with(['optionMappings' => static function ($query): void {
                $query->orderBy('internal_option_key');
            }])
            ->orderBy('field_binding_id')
            ->orderBy('external_field_key')
            ->get()
            ->map(static function (FieldMapping $mapping): FieldMappingRevisionEntry {
                $optionMappings = $mapping->optionMappings
                    ->map(static fn (FieldOptionMapping $option): FieldOptionMappingRevisionEntry => new FieldOptionMappingRevisionEntry(
                        internalOptionKey: $option->internal_option_key,
                        externalOptionValue: $option->external_option_value,
                    ))
                    ->all();

                return new FieldMappingRevisionEntry(
                    fieldBindingId: $mapping->field_binding_id,
                    externalFieldKey: $mapping->external_field_key,
                    optionMappings: $optionMappings,
                );
            })
            ->all();
    }

    public function updateConnectorExecutionConfiguration(
        ConnectorAccount $account,
        string $syncConfigurationId,
        ConnectorExecutionConfiguration $connectorExecutionConfiguration,
    ): SyncConfiguration {
        return $this->mutateLocked(
            $account,
            $syncConfigurationId,
            static function (SyncConfiguration $configuration) use ($connectorExecutionConfiguration): void {
                $configuration->connector_execution_configuration = $connectorExecutionConfiguration->payload();
            },
        );
    }
}
