<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Models\ConnectorAccount;
use App\Support\Connectors\AdobePaaS\Exceptions\IncompleteAdobePaaSCredentialsException;
use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;

final class AdobePaaSRequestContextFactory
{
    public function create(string $workspaceId, string $connectorAccountId): AdobePaaSRequestContext
    {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectorAccountId)
            ->first();

        if ($account === null) {
            throw new ConnectorAccountNotFoundException('Connector account was not found.');
        }

        if (! $account->is_enabled) {
            throw new IncompleteAdobePaaSCredentialsException(
                'Connector account is not enabled.',
            );
        }

        if ($account->auth_profile !== 'adobe_commerce_paas_oauth1_integration') {
            throw new IncompleteAdobePaaSCredentialsException(
                'Connector account auth profile is not supported for Adobe PaaS request context.',
            );
        }

        if ($account->base_url === null || $account->base_url === '' || $account->store_code === null || $account->store_code === '') {
            throw new IncompleteAdobePaaSCredentialsException(
                'Connector account does not have complete Adobe PaaS settings.',
            );
        }

        $credentials = AdobePaaSCredentialMapper::fromStorageArray($account->credentials ?? []);

        return new AdobePaaSRequestContext(
            baseUrl: $account->base_url,
            storeCode: $account->store_code,
            credentials: $credentials,
        );
    }
}
