<?php

namespace App\Support\Sync\AdobeProductExportSetup;

final readonly class SyncDataSetupTargetSummary
{
    public function __construct(
        public string $accountId,
        public string $platformName,
        public string $accountName,
        public bool $setupUsable,
        public string $targetLabel,
        public string $setupUrl,
    ) {}
}
