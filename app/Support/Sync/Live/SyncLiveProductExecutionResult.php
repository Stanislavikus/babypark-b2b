<?php

namespace App\Support\Sync\Live;

use App\Enums\SyncLiveOutcome;

final readonly class SyncLiveProductExecutionResult
{
    /**
     * @param  list<SyncLiveFinding>  $findings
     */
    public function __construct(
        public SyncLiveOutcome $outcome,
        public array $findings,
    ) {}
}
