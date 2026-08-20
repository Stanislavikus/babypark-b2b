<?php

namespace App\Support\Sync\AdobeProductExportSetup;

final readonly class SyncDataSetupLandingTargetSummary
{
    public function __construct(
        public string $accountId,
        public string $platformName,
        public string $accountName,
        public bool $setupUsable,
        public SyncDataSetupTargetKind $targetKind,
        public bool $setupActionVisible,
        public bool $previewActionVisible,
        public bool $liveActionVisible,
        public ?string $setupUrl,
        public ?string $previewUrl,
        public ?string $liveUrl,
    ) {}
}
