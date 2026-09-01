<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

use App\Enums\ConnectorComponentReadiness;
use App\Support\Connectors\ConnectorConnectionCheckResult;

final readonly class AdobeSafeSyncReadinessResult
{
    public function __construct(
        public ConnectorConnectionCheckResult $connectionResult,
        public ?ConnectorComponentReadiness $componentReadiness,
        public ?string $moduleVersion = null,
        public ?string $applicationVersion = null,
        public ?string $phpVersion = null,
    ) {}
}
