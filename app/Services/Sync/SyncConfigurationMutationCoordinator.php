<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\FieldMappingRevisionEntry;
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
            ->orderBy('field_binding_id')
            ->orderBy('external_field_key')
            ->get()
            ->map(static fn (FieldMapping $mapping): FieldMappingRevisionEntry => new FieldMappingRevisionEntry(
                fieldBindingId: $mapping->field_binding_id,
                externalFieldKey: $mapping->external_field_key,
            ))
            ->all();
    }
}
