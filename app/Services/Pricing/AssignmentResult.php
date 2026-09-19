<?php

namespace App\Services\Pricing;

final class AssignmentResult
{
    public function __construct(
        public readonly int $selectedCount,
        public readonly int $updatedCount,
        public readonly int $unchangedCount,
        public readonly int $replacedCount,
        public readonly int $clearedCount,
    ) {}
}
