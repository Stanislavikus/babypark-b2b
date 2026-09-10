<?php

namespace App\Support\Sync\EntityTrust;

final readonly class EntityTrustControlledFieldComparison
{
    public function __construct(
        public string $fieldKey,
        public string $label,
        public ?string $platformValue,
        public ?string $remoteValue,
    ) {}
}
