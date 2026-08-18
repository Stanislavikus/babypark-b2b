<?php

namespace App\Support\Sync\Preview\Merchant;

use App\Enums\SyncPreviewMerchantPageState;

final readonly class SyncPreviewMerchantReadModel
{
    public function __construct(
        public SyncPreviewMerchantPageState $pageState,
        public string $platformName,
        public string $accountName,
        public bool $accountSetupUsable,
        public bool $canManageSetup,
        public bool $canStartPreview,
        public bool $configurationChangedSinceRun,
        public ?string $displayedRunId,
        public ?string $configurationId,
        public ?SyncPreviewMerchantResultSummary $resultSummary,
        public bool $hasActiveRun,
    ) {}
}
