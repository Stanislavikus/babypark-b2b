<?php

namespace App\Services\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncSemanticOperation;
use App\Support\Sync\SyncOperationSet;

final readonly class UpdateSyncConfigurationInput
{
    /**
     * @param  list<SyncSemanticOperation>|null  $enabledOperations
     */
    public function __construct(
        public ?array $enabledOperations = null,
        public ?SyncConfigurationOperationalState $operationalState = null,
    ) {}

    public function hasSemanticChanges(): bool
    {
        return $this->enabledOperations !== null || $this->operationalState !== null;
    }

    public function resolvedOperationSet(SyncOperationSet $current): SyncOperationSet
    {
        if ($this->enabledOperations === null) {
            return $current;
        }

        return SyncOperationSet::fromOperations($this->enabledOperations);
    }

    public function resolvedOperationalState(SyncConfigurationOperationalState $current): SyncConfigurationOperationalState
    {
        return $this->operationalState ?? $current;
    }
}
