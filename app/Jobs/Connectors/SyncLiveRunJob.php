<?php

namespace App\Jobs\Connectors;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use App\Support\Sync\SyncRuntimeExecutionTiming;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SyncLiveRunJob implements Interruptible, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout;

    private SyncRuntimeExecutionTiming $executionTiming;

    private bool $interrupted = false;

    public function __construct(
        private readonly string $workspaceId,
        private readonly string $connectorAccountId,
        private readonly string $syncRunId,
        ?SyncRuntimeExecutionTiming $executionTiming = null,
    ) {
        $this->executionTiming = $executionTiming ?? SyncRuntimeExecutionTiming::snapshotFromCurrentConfig();
        $this->timeout = $this->executionTiming->jobTimeoutSeconds;
        $this->onConnection('database_connectors');
        $this->onQueue('connectors');
    }

    public function interrupted(int $signal): void
    {
        $this->interrupted = true;
    }

    public function wasInterrupted(): bool
    {
        return $this->interrupted;
    }

    public function handle(): void
    {
        try {
            $this->execute();
        } catch (\Throwable) {
            $this->terminalizeFailedRun();

            throw $this->normalizeThrowable();
        }
    }

    private function execute(): void
    {
        $reserved = DB::transaction(function (): ?SyncRun {
            $run = SyncRun::withoutWorkspaceScope()
                ->where('workspace_id', $this->workspaceId)
                ->where('id', $this->syncRunId)
                ->lockForUpdate()
                ->first();

            if ($run === null || $run->status !== SyncRunStatus::Queued) {
                return null;
            }

            $startedAt = now();
            $leaseTimestamps = $this->executionTiming->leaseTimestampsFrom($startedAt);

            $run->update([
                'status' => SyncRunStatus::Running,
                'started_at' => $startedAt,
                'writer_deadline_at' => $leaseTimestamps['writer_deadline_at'],
                'recoverable_after' => $leaseTimestamps['recoverable_after'],
            ]);

            return $run->refresh();
        });

        if ($reserved === null) {
            return;
        }

        throw SyncLiveRunJobExecutionException::executorNotImplemented();
    }

    private function terminalizeFailedRun(): void
    {
        SyncRun::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId)
            ->where('id', $this->syncRunId)
            ->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])
            ->update([
                'status' => SyncRunStatus::Failed,
                'completed_at' => now(),
            ]);
    }

    private function normalizeThrowable(): \Throwable
    {
        return new SyncLiveRunJobExecutionException('Live sync executor is not implemented.');
    }
}
