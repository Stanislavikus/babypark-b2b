<?php

namespace App\Support\Sync\Live\Merchant;

use App\Enums\SyncLiveMerchantLifecycleState;
use App\Enums\SyncLiveMerchantSetupBarrier;

final readonly class SyncLiveMerchantReadModel
{
    public function __construct(
        public SyncLiveMerchantLifecycleState $lifecycleState,
        public ?SyncLiveMerchantSetupBarrier $setupBarrier,
        public bool $liveSupportAvailable,
        public bool $previewPrerequisiteSatisfied,
        public bool $configurationReady,
        public bool $blockedByActiveRun,
        public bool $activePreviewBlocking,
        public bool $canStartLive,
        public bool $canManageSetup,
        public bool $configurationChangedSinceRun,
        public ?string $displayedRunId,
        public ?string $configurationId,
        public ?SyncLiveMerchantResultSummary $resultSummary,
        public ?SyncLivePreviewPrerequisiteSummary $previewPrerequisiteSummary,
        public bool $currentSetupRequired,
        public ?int $processedProductCount,
        public SyncLiveAdmissionReadiness $admissionReadiness,
    ) {}
}
