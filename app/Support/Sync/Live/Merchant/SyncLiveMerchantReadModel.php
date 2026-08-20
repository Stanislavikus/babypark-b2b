<?php

namespace App\Support\Sync\Live\Merchant;

use App\Enums\SyncLiveMerchantPageState;

final readonly class SyncLiveMerchantReadModel
{
    public function __construct(
        public SyncLiveMerchantPageState $pageState,
        public bool $canManageSetup,
        public bool $canStartLive,
        public bool $configurationChangedSinceRun,
        public ?string $displayedRunId,
        public ?string $configurationId,
        public ?SyncLiveMerchantResultSummary $resultSummary,
        public bool $hasActiveRun,
        public bool $currentSetupRequired,
        public bool $hasPreviewEvidence,
        public SyncLiveAdmissionReadiness $admissionReadiness,
        public ?int $processedProductCount = null,
    ) {}
}
