<?php

namespace App\Support\Connectors;

interface ConnectorAccountSchema
{
    public function validate(
        ConnectorAccountSettingsInput $settings,
        CredentialMutation $credentialMutation,
        ConnectorAccountMutationMode $mode,
    ): ValidatedConnectorAccountState;
}
