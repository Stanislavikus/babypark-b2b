<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeProductParentRemoteGetResult
{
    public function __construct(
        public AdobeProductRemoteGetClassification $classification,
        public ?AdobeProductParentObservedState $observedState = null,
    ) {}
}
