<?php

namespace App\Services\Connectors;

use App\Support\Connectors\AdobePaaS\AdobePaaSSettingsInput;
use App\Support\Connectors\ConnectorAccountSettingsInput;
use App\Support\Connectors\CredentialMutation;

final readonly class UpdateConnectorAccountInput
{
    public function __construct(
        public ConnectorAccountSettingsInput $settings,
        public CredentialMutation $credentialMutation,
    ) {}

    public static function adobePaas(
        string $baseUrl,
        string $storeCode,
        ?string $tenantContext,
        CredentialMutation $credentialMutation,
    ): self {
        return new self(
            settings: new AdobePaaSSettingsInput($baseUrl, $storeCode, $tenantContext),
            credentialMutation: $credentialMutation,
        );
    }
}
