<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

final readonly class AdobeStage3EValidationTransportArm
{
    public function __construct(
        public string $normalizedHost,
        public string $storeCode,
        public int $logicalEntityId,
    ) {}
}
