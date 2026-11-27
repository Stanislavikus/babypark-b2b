<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Enums\SyncLiveOutcome;

final readonly class AdobeConfigurableProductExecutionResult
{
    /**
     * @param  list<AdobeConfigurableCommandEvidence>  $commandEvidence
     */
    public function __construct(
        public SyncLiveOutcome $outcome,
        public array $commandEvidence,
    ) {}
}
