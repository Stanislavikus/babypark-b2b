<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final readonly class AdobeProductCustomAttributeClearIntent
{
    public function __construct(
        public string $attributeCode,
        public ?string $frontendInput,
        public ?string $scope,
        public ?bool $isRequired,
    ) {}
}
