<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class SyncRuntimeTimingConfigurationException extends RuntimeException
{
    public static function nonPositiveJobTimeout(): self
    {
        return new self('sync_runtime.live_job_timeout_seconds must be greater than zero.');
    }

    public static function nonPositiveMaxInflight(): self
    {
        return new self('sync_runtime.max_inflight_external_request_seconds must be greater than zero.');
    }

    public static function nonPositiveQueuedGrace(): self
    {
        return new self('sync_runtime.queued_undispatched_grace_seconds must be greater than zero.');
    }

    public static function executionWindowExceedsConnectorRetryAfter(int $executionWindowSeconds, int $retryAfterSeconds): self
    {
        return new self(
            "sync_runtime live timeout plus max inflight ({$executionWindowSeconds}s) must be less than database_connectors retry_after ({$retryAfterSeconds}s).",
        );
    }
}
