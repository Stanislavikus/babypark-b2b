<?php

namespace App\Services\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Support\Sync\SyncExternalContext;
use App\Support\Sync\SyncOperationSet;

final readonly class CreateSyncConfigurationInput
{
    /**
     * @param  list<SyncSemanticOperation>  $enabledOperations
     */
    public function __construct(
        public SyncDataDomain $dataDomain,
        public SyncExternalContext $externalContext,
        public array $enabledOperations,
        public SyncConfigurationOperationalState $operationalState = SyncConfigurationOperationalState::Enabled,
    ) {}

    public function operationSet(): SyncOperationSet
    {
        return SyncOperationSet::fromOperations($this->enabledOperations);
    }
}
