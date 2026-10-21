<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Services\Connectors\AdobePaaSConnectionCheckService;
use App\Support\Connectors\AdobePaaS\Exceptions\AdobeStoreConfigReadException;
use App\Support\Connectors\AdobePaaS\Exceptions\IncompleteAdobePaaSCredentialsException;
use App\Support\Connectors\AdobePaaS\Exceptions\InvalidAdobePaaSRequestContextException;
use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;
use App\Support\Connectors\Transport\DestinationRequestMismatch;
use App\Support\Connectors\Transport\TransportConfigurationException;
use App\Support\Sync\Live\ConnectorLiveRuntimeReadiness;

final class AdobeProductExportLiveRuntimeReadiness implements ConnectorLiveRuntimeReadiness
{
    public function __construct(
        private readonly AdobePaaSConnectionCheckService $connectionCheckService,
        private readonly AdobeStoreConfigReader $storeConfigReader,
    ) {}

    public function isReady(string $workspaceId, string $connectorAccountId): bool
    {
        try {
            $baseline = $this->connectionCheckService->execute($workspaceId, $connectorAccountId);

            if (! $baseline->succeeded) {
                return false;
            }

            $this->storeConfigReader->readBaseCurrency($workspaceId, $connectorAccountId);

            return true;
        } catch (
            AdobeStoreConfigReadException
            |DestinationRequestMismatch
            |IncompleteAdobePaaSCredentialsException
            |InvalidAdobePaaSRequestContextException
            |ConnectorAccountNotFoundException
            |TransportConfigurationException
        ) {
            return false;
        }
    }
}
