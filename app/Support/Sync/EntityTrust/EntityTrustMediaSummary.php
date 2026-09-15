<?php

namespace App\Support\Sync\EntityTrust;

final readonly class EntityTrustMediaSummary
{
    public function __construct(
        public int $declaredImageCount,
        public string $declaredRolesSummary,
        public ?int $remoteImageEntryCount,
        public ?string $remoteRolesSummary,
    ) {}
}
