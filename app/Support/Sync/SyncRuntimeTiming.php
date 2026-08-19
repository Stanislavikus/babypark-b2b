<?php

namespace App\Support\Sync;

use Carbon\CarbonInterface;

final class SyncRuntimeTiming
{
    public function liveJobTimeoutSeconds(): int
    {
        return (int) config('sync_runtime.live_job_timeout_seconds');
    }

    public function maxInflightExternalRequestSeconds(): int
    {
        return (int) config('sync_runtime.max_inflight_external_request_seconds');
    }

    public function queuedUndispatchedGraceSeconds(): int
    {
        return (int) config('sync_runtime.queued_undispatched_grace_seconds');
    }

    public function queuedAbandonAfter(CarbonInterface $admittedAt): CarbonInterface
    {
        return $admittedAt->copy()->addSeconds($this->queuedUndispatchedGraceSeconds());
    }

    /**
     * @return array{started_at: CarbonInterface, writer_deadline_at: CarbonInterface, recoverable_after: CarbonInterface}
     */
    public function reservationLeaseTimestamps(CarbonInterface $startedAt): array
    {
        $writerDeadlineAt = $startedAt->copy()->addSeconds($this->liveJobTimeoutSeconds());
        $recoverableAfter = $writerDeadlineAt->copy()->addSeconds($this->maxInflightExternalRequestSeconds());

        return [
            'started_at' => $startedAt,
            'writer_deadline_at' => $writerDeadlineAt,
            'recoverable_after' => $recoverableAfter,
        ];
    }
}
