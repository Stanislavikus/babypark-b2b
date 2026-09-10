<?php

namespace App\Services\Sync;

use App\Support\Sync\Exceptions\SyncRuntimeTimingConfigurationException;
use App\Support\Sync\SyncRuntimeAdmissionTiming;
use App\Support\Sync\SyncRuntimeExecutionTiming;

final class SyncRuntimeTimingResolver
{
    public function resolveAdmissionTiming(): SyncRuntimeAdmissionTiming
    {
        $jobTimeoutSeconds = (int) config('sync_runtime.live_job_timeout_seconds');
        $maxInflightSeconds = (int) config('sync_runtime.max_inflight_external_request_seconds');
        $queuedGraceSeconds = (int) config('sync_runtime.queued_undispatched_grace_seconds');
        $retryAfterSeconds = (int) config('queue.connections.database_connectors.retry_after');

        if ($jobTimeoutSeconds <= 0) {
            throw SyncRuntimeTimingConfigurationException::nonPositiveJobTimeout();
        }

        if ($maxInflightSeconds <= 0) {
            throw SyncRuntimeTimingConfigurationException::nonPositiveMaxInflight();
        }

        if ($queuedGraceSeconds <= 0) {
            throw SyncRuntimeTimingConfigurationException::nonPositiveQueuedGrace();
        }

        $executionWindowSeconds = $jobTimeoutSeconds + $maxInflightSeconds;

        if ($executionWindowSeconds >= $retryAfterSeconds) {
            throw SyncRuntimeTimingConfigurationException::executionWindowExceedsConnectorRetryAfter(
                $executionWindowSeconds,
                $retryAfterSeconds,
            );
        }

        return new SyncRuntimeAdmissionTiming(
            new SyncRuntimeExecutionTiming($jobTimeoutSeconds, $maxInflightSeconds),
            $queuedGraceSeconds,
        );
    }
}
