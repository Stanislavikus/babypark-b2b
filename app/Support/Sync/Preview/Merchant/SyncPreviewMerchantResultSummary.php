<?php

namespace App\Support\Sync\Preview\Merchant;

final readonly class SyncPreviewMerchantResultSummary
{
    public function __construct(
        public int $readyCount,
        public int $warningCount,
        public int $blockedCount,
        public int $needsAttentionCount,
        public ?string $completedAtLabel,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ready_count' => $this->readyCount,
            'warning_count' => $this->warningCount,
            'blocked_count' => $this->blockedCount,
            'needs_attention_count' => $this->needsAttentionCount,
            'completed_at_label' => $this->completedAtLabel,
        ];
    }
}
