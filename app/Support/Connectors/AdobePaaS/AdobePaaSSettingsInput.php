<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\ConnectorAccountSettingsInput;

final readonly class AdobePaaSSettingsInput implements ConnectorAccountSettingsInput
{
    public function __construct(
        public string $baseUrl,
        public string $storeCode,
        public ?string $tenantContext = null,
    ) {}
}
