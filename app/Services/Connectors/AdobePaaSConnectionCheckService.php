<?php

namespace App\Services\Connectors;

use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\ConnectorConnectionCheckResult;

final class AdobePaaSConnectionCheckService
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobePaaSConnectionCheckCapability $capability,
    ) {}

    public function execute(
        string $workspaceId,
        string $connectorAccountId,
    ): ConnectorConnectionCheckResult {
        $context = $this->contextFactory->create(
            workspaceId: $workspaceId,
            connectorAccountId: $connectorAccountId,
        );

        return $this->capability->checkConnection($context);
    }
}
