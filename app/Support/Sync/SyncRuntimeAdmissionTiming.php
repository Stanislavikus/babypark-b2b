<?php

namespace App\Support\Sync;

final readonly class SyncRuntimeAdmissionTiming
{
    public function __construct(
        public SyncRuntimeExecutionTiming $executionTiming,
        public int $queuedUndispatchedGraceSeconds,
    ) {}
}
