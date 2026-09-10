<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\ConnectorAccountMutationMode;
use App\Support\Connectors\ConnectorAccountSchema;
use App\Support\Connectors\ConnectorAccountSettingsInput;
use App\Support\Connectors\CredentialMutation;
use App\Support\Connectors\Exceptions\ConnectorAccountProfileInputMismatchException;
use App\Support\Connectors\Exceptions\ConnectorAccountSettingsValidationException;
use App\Support\Connectors\ValidatedConnectorAccountState;

final class AdobePaaSAccountSchema implements ConnectorAccountSchema
{
    public function validate(
        ConnectorAccountSettingsInput $settings,
        CredentialMutation $credentialMutation,
        ConnectorAccountMutationMode $mode,
    ): ValidatedConnectorAccountState {
        if (! $settings instanceof AdobePaaSSettingsInput) {
            throw new ConnectorAccountProfileInputMismatchException(
                'Settings input does not match the Adobe PaaS connector account schema.',
            );
        }

        $credentialMutation->assertAllowedForMode($mode);

        $baseUrl = AdobePaaSBaseUrl::parse($settings->baseUrl)->value;
        $storeCode = AdobePaaSStoreCode::parse($settings->storeCode)->value;
        $tenantContext = $this->normalizeTenantContext($settings->tenantContext);

        return new ValidatedConnectorAccountState(
            baseUrl: $baseUrl,
            storeCode: $storeCode,
            tenantContext: $tenantContext,
            settings: [],
        );
    }

    private function normalizeTenantContext(?string $tenantContext): ?string
    {
        if ($tenantContext === null) {
            return null;
        }

        $trimmed = trim($tenantContext);

        if ($trimmed === '') {
            return null;
        }

        if (strlen($trimmed) > 255) {
            throw new ConnectorAccountSettingsValidationException(
                'Connector account tenant context must not exceed 255 characters.',
            );
        }

        return $trimmed;
    }
}
