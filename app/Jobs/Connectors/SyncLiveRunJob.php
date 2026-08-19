<?php

namespace App\Jobs\Connectors;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use App\Support\Sync\SyncRuntimeTiming;
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

    private bool $interrupted = false;

    public function __construct(
        private readonly string $workspaceId,
        private readonly string $connectorAccountId,
        private readonly string $syncRunId,
    ) {
        $this->timeout = (int) config('sync_runtime.live_job_timeout_seconds');
        $this->onConnection('database_connectors');
        $this->onQueue('connectors');
    }

    public function interrupted(int $signal): void
    {
        $this->interrupted = true;
    }

    public function handle(SyncRuntimeTiming $runtimeTiming): void
    {
        try {
            $this->execute($runtimeTiming);
        } catch (\Throwable) {
            $this->terminalizeFailedRun();

            throw $this->normalizeThrowable();
        }
    }

    private function execute(SyncRuntimeTiming $runtimeTiming): void
    {
        $reserved = DB::transaction(function () use ($runtimeTiming): ?SyncRun {
            $run = SyncRun::withoutWorkspaceScope()
                ->where('workspace_id', $this->workspaceId)
                ->where('id', $this->syncRunId)
                ->lockForUpdate()
                ->first();

            if ($run === null || $run->status !== SyncRunStatus::Queued) {
                return null;
            }

            $startedAt = now();
            $lease = $runtimeTiming->reservationLeaseTimestamps($startedAt);

            $run->update([
                'status' => SyncRunStatus::Running,
                'started_at' => $lease['started_at'],
                'writer_deadline_at' => $lease['writer_deadline_at'],
                'recoverable_after' => $lease['recoverable_after'],
            ]);

            return $run->refresh();
        });

        if ($reserved === null) {
            return;
        }

        throw new SyncLiveRunJobExecutionException;
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
        return new SyncLiveRunJobExecutionException;
    }
}
