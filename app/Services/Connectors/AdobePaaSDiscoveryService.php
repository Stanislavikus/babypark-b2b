<?php

namespace App\Services\Connectors;

use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSource;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\ConnectorSchemaSourceEndpointPathValidator;
use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceInvalidAfterReservationException;

final class AdobePaaSDiscoveryService
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobePaaSDiscoveryCapability $capability,
        private readonly ConnectorDiscoverySourceResolver $sourceResolver,
        private readonly ConnectorSchemaSourceEndpointPathValidator $endpointPathValidator,
    ) {}

    public function execute(
        string $workspaceId,
        string $connectorAccountId,
        string $discoveryRunId,
    ): ConnectorDiscoveryAttemptResult {
        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('id', $discoveryRunId)
            ->first();

        if ($run === null) {
            throw new ConnectorAccountNotFoundException('Discovery run was not found.');
        }

        $source = ConnectorSchemaSource::query()->find($run->connector_schema_source_id);

        if ($source === null) {
            throw new ConnectorDiscoverySourceInvalidAfterReservationException('Schema source was not found.');
        }

        $account = $run->account()->withoutGlobalScopes()->firstOrFail();

        if (! $this->sourceResolver->reverify($account, $source)) {
            throw new ConnectorDiscoverySourceInvalidAfterReservationException('Schema source is no longer valid.');
        }

        $endpointPath = $this->endpointPathValidator->normalize($source->endpoint_path);
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);

        return $this->capability->discover($context, $endpointPath);
    }
}
