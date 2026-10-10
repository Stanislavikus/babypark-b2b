<?php

namespace App\Services\Pricing;

final class AssignmentPreview
{
    public function __construct(
        public readonly int $selectedCount,
        public readonly int $changedCount,
        public readonly int $replacedCount,
        public readonly int $unchangedCount,
        public readonly int $clearedCount,
    ) {}
}
