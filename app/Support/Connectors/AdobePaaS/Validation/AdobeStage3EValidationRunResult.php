<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

final readonly class AdobeStage3EValidationRunResult
{
    /**
     * @param  list<string>  $messages
     * @param  list<string>  $failureCodes
     */
    public function __construct(
        public AdobeStage3EValidationOutcome $outcome,
        public string $artifactPath,
        public array $messages = [],
        public array $failureCodes = [],
    ) {}

    public function exitCode(): int
    {
        return $this->outcome === AdobeStage3EValidationOutcome::Pass ? 0 : 1;
    }
}
