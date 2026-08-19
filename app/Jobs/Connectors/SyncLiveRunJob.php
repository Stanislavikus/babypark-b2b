<?php

namespace App\Jobs\Connectors;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
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
            $jobTimeoutSeconds = (int) config('sync_runtime.live_job_timeout_seconds');
            $maxInflightSeconds = (int) config('sync_runtime.max_inflight_external_request_seconds');
            $writerDeadlineAt = $startedAt->copy()->addSeconds($jobTimeoutSeconds);

            $run->update([
                'status' => SyncRunStatus::Running,
                'started_at' => $startedAt,
                'writer_deadline_at' => $writerDeadlineAt,
                'recoverable_after' => $writerDeadlineAt->copy()->addSeconds($maxInflightSeconds),
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
