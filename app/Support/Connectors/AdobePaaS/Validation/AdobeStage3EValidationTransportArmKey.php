<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

final readonly class AdobeStage3EValidationTransportArmKey
{
    public function __construct(
        public string $method,
        public string $resourceCategory,
        public string $externalIdentifier,
    ) {}

    public function signature(): string
    {
        return strtolower($this->method).'|'.$this->resourceCategory.'|'.$this->externalIdentifier;
    }
}
