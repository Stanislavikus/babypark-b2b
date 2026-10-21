<?php

namespace App\Support\Sync\Live\Merchant;

final readonly class SyncLiveMerchantResultSummary
{
    public function __construct(
        public int $synchronizedCount,
        public int $notAppliedCount,
        public int $partialCount,
        public int $ambiguousCount,
        public int $needsAttentionCount,
        public ?string $completedAtLabel,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'synchronized_count' => $this->synchronizedCount,
            'not_applied_count' => $this->notAppliedCount,
            'partial_count' => $this->partialCount,
            'ambiguous_count' => $this->ambiguousCount,
            'needs_attention_count' => $this->needsAttentionCount,
            'completed_at_label' => $this->completedAtLabel,
        ];
    }
}
