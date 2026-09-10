<?php

namespace App\Support\Sync\EntityTrust;

final readonly class EntityTrustMerchantFieldComparison
{
    public function __construct(
        public string $field_key,
        public string $label,
        public ?string $platform_value,
        public ?string $remote_value,
    ) {}
}
