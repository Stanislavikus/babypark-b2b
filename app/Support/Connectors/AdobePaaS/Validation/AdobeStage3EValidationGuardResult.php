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
        public ?AdobeStage3EValidationResolvedSubject $subject = null,
    ) {}

    public static function fail(array $failureCodes): self
    {
        return new self(false, array_values(array_unique($failureCodes)));
    }

    public static function pass(AdobeStage3EValidationResolvedSubject $subject): self
    {
        return new self(true, [], $subject);
    }
}
