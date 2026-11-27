<?php

namespace App\Support\Sync\Live\Merchant;

final readonly class SyncLivePreviewPrerequisiteSummary
{
    public function __construct(
        public int $readyCount,
        public int $warningCount,
        public int $blockedCount,
    ) {}
}
