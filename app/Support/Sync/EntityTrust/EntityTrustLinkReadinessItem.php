<?php

namespace App\Support\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustReadinessStatus;

final readonly class EntityTrustLinkReadinessItem
{
    public function __construct(
        public string $productId,
        public string $productName,
        public ?string $primarySku,
        public EntityTrustReadinessStatus $status,
        public bool $isConfigurableFamily,
    ) {}
}
