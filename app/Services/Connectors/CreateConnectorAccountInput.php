<?php

namespace App\Services\Connectors;

use App\Support\Connectors\AdobePaaS\AdobePaaSSettingsInput;
use App\Support\Connectors\ConnectorAccountSettingsInput;
use App\Support\Connectors\CredentialMutation;

final readonly class CreateConnectorAccountInput
{
    public function __construct(
        public string $connectorDefinitionId,
        public string $name,
        public string $authProfile,
        public ConnectorAccountSettingsInput $settings,
        public CredentialMutation $credentialMutation,
    ) {}

    public static function adobePaas(
        string $connectorDefinitionId,
        string $name,
        string $baseUrl,
        string $storeCode,
        ?string $tenantContext,
        CredentialMutation $credentialMutation,
    ): self {
        return new self(
            connectorDefinitionId: $connectorDefinitionId,
            name: $name,
            authProfile: 'adobe_commerce_paas_oauth1_integration',
            settings: new AdobePaaSSettingsInput($baseUrl, $storeCode, $tenantContext),
            credentialMutation: $credentialMutation,
        );
    }
}
