<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

final readonly class AdobeStage3EValidationGuardResult
{
    /**
     * @param  list<string>  $failureCodes
     */
    public function __construct(
        public bool $passed,
        public array $failureCodes = [],
    ) {}

    public static function pass(): self
    {
        return new self(true);
    }

    /**
     * @param  list<string>  $failureCodes
     */
    public static function fail(array $failureCodes): self
    {
        return new self(false, $failureCodes);
    }
}
