<?php

namespace App\Support\Sync\Live;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;

final class SyncRunConsequentialWriteGate implements SyncLiveConsequentialWriteGate
{
    public function __construct(
        private readonly SyncRun $run,
    ) {}

    public function permitsConsequentialWrite(): bool
    {
        if ($this->run->status !== SyncRunStatus::Running) {
            return false;
        }

        if ($this->run->writer_deadline_at === null) {
            return false;
        }

        return now()->lessThan($this->run->writer_deadline_at);
    }

    public function permitsProductExecution(): bool
    {
        return $this->permitsConsequentialWrite();
    }
}
