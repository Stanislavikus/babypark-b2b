<?php

namespace App\Support\Sync;

use Carbon\CarbonInterface;

final readonly class SyncRuntimeExecutionTiming
{
    public function __construct(
        public int $jobTimeoutSeconds,
        public int $maxInflightExternalRequestSeconds,
    ) {}

    public static function snapshotFromCurrentConfig(): self
    {
        return new self(
            (int) config('sync_runtime.live_job_timeout_seconds'),
            (int) config('sync_runtime.max_inflight_external_request_seconds'),
        );
    }

    public function writerDeadlineFrom(CarbonInterface $startedAt): CarbonInterface
    {
        return $startedAt->copy()->addSeconds($this->jobTimeoutSeconds);
    }

    public function recoverableAfterFrom(CarbonInterface $writerDeadlineAt): CarbonInterface
    {
        return $writerDeadlineAt->copy()->addSeconds($this->maxInflightExternalRequestSeconds);
    }

    /**
     * @return array{writer_deadline_at: CarbonInterface, recoverable_after: CarbonInterface}
     */
    public function leaseTimestampsFrom(CarbonInterface $startedAt): array
    {
        $writerDeadlineAt = $this->writerDeadlineFrom($startedAt);

        return [
            'writer_deadline_at' => $writerDeadlineAt,
            'recoverable_after' => $this->recoverableAfterFrom($writerDeadlineAt),
        ];
    }
}
