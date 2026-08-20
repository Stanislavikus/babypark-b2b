<?php

namespace App\Support\Sync\Live\Merchant;

final readonly class SyncLiveAdmissionReadiness
{
    public function __construct(
        public bool $hasLivePermission,
        public bool $liveSupportEnabled,
        public bool $configurationReady,
        public bool $hasPreviewEvidence,
        public bool $activeRunBlocking,
        public bool $canStartLive,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'has_live_permission' => $this->hasLivePermission,
            'live_support_enabled' => $this->liveSupportEnabled,
            'configuration_ready' => $this->configurationReady,
            'has_preview_evidence' => $this->hasPreviewEvidence,
            'active_run_blocking' => $this->activeRunBlocking,
            'can_start_live' => $this->canStartLive,
        ];
    }
}
