<?php

namespace App\Support\Sync\Live;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;

final class SyncRunConsequentialWriteGate implements SyncLiveConsequentialWriteGate
{
    public function __construct(
        private readonly string $workspaceId,
        private readonly string $syncRunId,
    ) {}

    public function permitsConsequentialWrite(): bool
    {
        $run = SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->where('id', $this->syncRunId)
            ->first();

        if ($run === null) {
            return false;
        }

        if ($run->status !== SyncRunStatus::Running) {
            return false;
        }

        if ($run->writer_deadline_at === null) {
            return false;
        }

        return now()->lessThan($run->writer_deadline_at);
    }

    public function permitsProductExecution(): bool
    {
        return $this->permitsConsequentialWrite();
    }
}
