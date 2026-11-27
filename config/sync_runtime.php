<?php

return [
    'live_job_timeout_seconds' => (int) env('SYNC_RUNTIME_LIVE_JOB_TIMEOUT_SECONDS', 900),

    'max_inflight_external_request_seconds' => (int) env('SYNC_RUNTIME_MAX_INFLIGHT_EXTERNAL_REQUEST_SECONDS', 60),

    'queued_undispatched_grace_seconds' => (int) env('SYNC_RUNTIME_QUEUED_UNDISPATCHED_GRACE_SECONDS', 60),
];
