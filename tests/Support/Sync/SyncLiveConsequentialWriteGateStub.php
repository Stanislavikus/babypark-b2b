<?php

namespace Tests\Support\Sync;

use App\Support\Sync\Live\SyncLiveConsequentialWriteGate;

final class SyncLiveConsequentialWriteGateStub implements SyncLiveConsequentialWriteGate
{
    public function __construct(
        private readonly bool $permitsConsequentialWrite = true,
        private readonly bool $permitsProductExecution = true,
    ) {}

    public function permitsConsequentialWrite(): bool
    {
        return $this->permitsConsequentialWrite;
    }

    public function permitsProductExecution(): bool
    {
        return $this->permitsProductExecution;
    }
}
