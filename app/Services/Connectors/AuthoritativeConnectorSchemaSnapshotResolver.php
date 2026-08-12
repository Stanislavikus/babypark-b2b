<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceResolutionException;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceResolutionReason;
use App\Support\Sync\Exceptions\AuthoritativeDiscoveryValidationException;

final class AuthoritativeConnectorSchemaSnapshotResolver
{
    public function __construct(
        private readonly ConnectorDiscoverySourceResolver $discoverySourceResolver,
    ) {}

    public function resolveSnapshot(ConnectorAccount $account): ?ConnectorSchemaSnapshot
    {
        try {
            $source = $this->discoverySourceResolver->resolve($account);
        } catch (ConnectorDiscoverySourceResolutionException $exception) {
            return null;
        }

        return ConnectorSchemaSnapshot::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('connector_schema_source_id', $source->id)
            ->whereHas('discoveryRun', function ($query) use ($account): void {
                $query->withoutGlobalScopes()
                    ->where('workspace_id', $account->workspace_id)
                    ->where('status', ConnectorDiscoveryRunStatus::Succeeded);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function assertResolvableDiscoverySource(ConnectorAccount $account): void
    {
        try {
            $this->discoverySourceResolver->resolve($account);
        } catch (ConnectorDiscoverySourceResolutionException $exception) {
            $reason = match ($exception->reason) {
                ConnectorDiscoverySourceResolutionReason::Missing => 'missing primary discovery source',
                ConnectorDiscoverySourceResolutionReason::Ambiguous => 'ambiguous primary discovery source',
            };

            throw AuthoritativeDiscoveryValidationException::discoverySourceUnavailable($reason);
        }
    }

    public function externalFieldKeyExists(ConnectorAccount $account, string $externalFieldKey): bool
    {
        $snapshot = $this->resolveSnapshot($account);

        if ($snapshot === null) {
            return false;
        }

        return ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('snapshot_id', $snapshot->id)
            ->where('external_field_key', $externalFieldKey)
            ->exists();
    }
}
