<?php

namespace App\Support\Sync\Preview;

use App\Enums\SyncPreviewOutcome;

final readonly class SyncPreviewPlanResult
{
    /**
     * @param  list<SyncPreviewFinding>  $findings
     */
    public function __construct(
        public SyncPreviewOutcome $outcome,
        public array $findings,
        public ?object $connectorPlan = null,
    ) {}
}
