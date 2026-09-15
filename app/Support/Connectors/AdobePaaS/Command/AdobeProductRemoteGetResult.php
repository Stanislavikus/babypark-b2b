<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeProductRemoteGetResult
{
    public function __construct(
        public AdobeProductRemoteGetClassification $classification,
        public ?AdobeProductObservedState $observedState = null,
    ) {}
}
