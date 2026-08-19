<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Live job timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Maximum duration allowed for a Live sync job worker attempt before the
    | queue driver may terminate it. Snapshotted onto SyncRun at reservation.
    |
    */

    'live_job_timeout_seconds' => (int) env('SYNC_RUNTIME_LIVE_JOB_TIMEOUT_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Max in-flight external request (seconds)
    |--------------------------------------------------------------------------
    |
    | Safety envelope added after writer_deadline_at when computing
    | recoverable_after. Stage 3A provisional bound — not Adobe write proof.
    |
    */

    'max_inflight_external_request_seconds' => (int) env('SYNC_RUNTIME_MAX_INFLIGHT_EXTERNAL_REQUEST_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Queued undispatched grace (seconds)
    |--------------------------------------------------------------------------
    |
    | Earliest point at which an unconfirmed Queued admission may be recovered.
    |
    */

    'queued_undispatched_grace_seconds' => (int) env('SYNC_RUNTIME_QUEUED_UNDISPATCHED_GRACE_SECONDS', 60),

];
