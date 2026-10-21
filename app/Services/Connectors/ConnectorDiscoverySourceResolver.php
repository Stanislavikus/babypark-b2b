<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorSchemaAcquisitionMode;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaSource;
use App\Support\Connectors\ConnectorSchemaSourceEndpointPathValidator;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceResolutionException;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceResolutionReason;

final class ConnectorDiscoverySourceResolver
{
    public function __construct(
        private readonly ConnectorSchemaSourceEndpointPathValidator $endpointPathValidator,
    ) {}

    public function resolve(ConnectorAccount $account): ConnectorSchemaSource
    {
        $candidates = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $account->connector_definition_id)
            ->where('schema_scope', ConnectorSchemaScope::Account)
            ->where('source_kind', ConnectorSchemaSourceKind::AccountApi)
            ->where('acquisition_mode', ConnectorSchemaAcquisitionMode::LiveFetch)
            ->where('is_primary', true)
            ->get()
            ->filter(fn (ConnectorSchemaSource $source): bool => $this->endpointPathValidator->isValid($source->endpoint_path));

        $count = $candidates->count();

        if ($count === 0) {
            throw new ConnectorDiscoverySourceResolutionException(
                ConnectorDiscoverySourceResolutionReason::Missing,
                0,
            );
        }

        if ($count > 1) {
            throw new ConnectorDiscoverySourceResolutionException(
                ConnectorDiscoverySourceResolutionReason::Ambiguous,
                $count,
            );
        }

        return $candidates->first();
    }

    public function reverify(ConnectorAccount $account, ConnectorSchemaSource $source): bool
    {
        if ($source->connector_definition_id !== $account->connector_definition_id) {
            return false;
        }

        if ($source->schema_scope !== ConnectorSchemaScope::Account) {
            return false;
        }

        if ($source->source_kind !== ConnectorSchemaSourceKind::AccountApi) {
            return false;
        }

        if ($source->acquisition_mode !== ConnectorSchemaAcquisitionMode::LiveFetch) {
            return false;
        }

        if (! $source->is_primary) {
            return false;
        }

        return $this->endpointPathValidator->isValid($source->endpoint_path);
    }
}
