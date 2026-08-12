<?php

namespace App\Services\Sync;

use App\Enums\SyncDataDomain;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use App\Support\Sync\Exceptions\SyncConfigurationConflictException;
use App\Support\Sync\Exceptions\SyncConfigurationNotFoundException;
use App\Support\Sync\Exceptions\UnsupportedSyncOperationException;
use App\Support\Sync\SyncConfigurationRevisionHasher;
use App\Support\Sync\SyncOperationSet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SyncConfigurationService
{
    public function __construct(
        private readonly ConnectorSyncSupportResolver $syncSupportResolver,
        private readonly SyncConfigurationRevisionHasher $revisionHasher,
        private readonly SyncConfigurationConstraintViolationClassifier $constraintViolationClassifier,
    ) {}

    public function create(
        ConnectorAccount $account,
        CreateSyncConfigurationInput $input,
    ): SyncConfiguration {
        $operationSet = $input->operationSet();

        $this->assertOperationsSupported($account, $input->dataDomain, $operationSet);

        $revision = $this->revisionHasher->hash($operationSet, $input->operationalState);

        try {
            return DB::transaction(function () use ($account, $input, $operationSet, $revision): SyncConfiguration {
                return SyncConfiguration::withoutWorkspaceScope()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $account->workspace_id,
                    'connector_account_id' => $account->id,
                    'data_domain' => $input->dataDomain,
                    'external_context' => $input->externalContext->payload(),
                    'external_context_key' => $input->externalContext->uniquenessKey(),
                    'enabled_operations' => $operationSet->values(),
                    'operational_state' => $input->operationalState,
                    'configuration_revision' => $revision,
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->constraintViolationClassifier->isIdentityUniquenessConflict($exception)) {
                throw SyncConfigurationConflictException::duplicateIdentity(previous: $exception);
            }

            throw $exception;
        }
    }

    public function update(
        ConnectorAccount $account,
        string $syncConfigurationId,
        UpdateSyncConfigurationInput $input,
    ): SyncConfiguration {
        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('id', $syncConfigurationId)
            ->first();

        if ($configuration === null) {
            throw SyncConfigurationNotFoundException::forId($syncConfigurationId);
        }

        if (! $input->hasSemanticChanges()) {
            $configuration->touch();

            return $configuration->refresh();
        }

        $currentOperationSet = $configuration->enabledOperationSet();
        $nextOperationSet = $input->resolvedOperationSet($currentOperationSet);
        $nextOperationalState = $input->resolvedOperationalState($configuration->operational_state);

        $this->assertOperationsSupported($account, $configuration->data_domain, $nextOperationSet);

        $nextRevision = $this->revisionHasher->hash($nextOperationSet, $nextOperationalState);

        if ($nextRevision === $configuration->configuration_revision) {
            $configuration->touch();

            return $configuration->refresh();
        }

        $configuration->enabled_operations = $nextOperationSet->operations();
        $configuration->operational_state = $nextOperationalState;
        $configuration->configuration_revision = $nextRevision;
        $configuration->save();

        return $configuration->refresh();
    }

    private function assertOperationsSupported(
        ConnectorAccount $account,
        SyncDataDomain $dataDomain,
        SyncOperationSet $operationSet,
    ): void {
        foreach ($operationSet->operations() as $operation) {
            if (! $this->syncSupportResolver->supports($account, $dataDomain, $operation)) {
                throw UnsupportedSyncOperationException::forPair($dataDomain, $operation);
            }
        }
    }
}
