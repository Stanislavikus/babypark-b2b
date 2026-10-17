<?php

namespace Tests\Support\Connectors;

use App\Support\Connectors\ConnectorAccountMutationMode;
use App\Support\Connectors\ConnectorAccountSchema;
use App\Support\Connectors\ConnectorAccountSettingsInput;
use App\Support\Connectors\CredentialMutation;
use App\Support\Connectors\ValidatedConnectorAccountState;

final class TestSyncSupportConnectorAccountSchema implements ConnectorAccountSchema
{
    public function validate(
        ConnectorAccountSettingsInput $settings,
        CredentialMutation $credentialMutation,
        ConnectorAccountMutationMode $mode,
    ): ValidatedConnectorAccountState {
        return new ValidatedConnectorAccountState(
            baseUrl: 'https://example.com',
            storeCode: 'default',
            tenantContext: null,
            settings: [],
        );
    }
}
