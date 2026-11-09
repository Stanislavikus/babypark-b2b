<?php

namespace App\Support\Sync\Live;

use App\Enums\SyncRunStatus;
use App\Models\SyncConfiguration;
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

        if ($run->writer_deadline_at === null || ! now()->lessThan($run->writer_deadline_at)) {
            return false;
        }

        $currentRevision = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->where('id', $run->sync_configuration_id)
            ->value('configuration_revision');

        return is_string($currentRevision)
            && hash_equals((string) $run->configuration_revision, $currentRevision);
    }

    public function permitsProductExecution(): bool
    {
        return $this->permitsConsequentialWrite();
    }
}
