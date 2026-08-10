<?php

namespace App\Support\Connectors\Integrations;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Models\ConnectorAccount;
use Illuminate\Support\Collection;

/**
 * Exact §5 rollup: disabled accounts excluded from health evaluation.
 */
final class PlatformConnectionHealthRollup
{
    /**
     * @param  Collection<int, ConnectorAccount>|iterable<int, ConnectorAccount>  $accounts
     */
    public function rollup(iterable $accounts): PlatformConnectionHealth
    {
        $accounts = Collection::make($accounts)->values();

        if ($accounts->isEmpty()) {
            return new PlatformConnectionHealth(
                accountCount: 0,
                enabledCount: 0,
                disabledCount: 0,
                attentionRequiredCount: 0,
                temporarilyUnavailableCount: 0,
                untestedCount: 0,
                connectedCount: 0,
                connectionStatus: null,
                enabledStatuses: [],
            );
        }

        $disabled = $accounts->filter(fn (ConnectorAccount $account): bool => ! $account->is_enabled);
        $enabled = $accounts->filter(fn (ConnectorAccount $account): bool => $account->is_enabled);

        $enabledStatuses = $enabled
            ->map(fn (ConnectorAccount $account): ConnectorAccountConnectionStatus => $account->connection_status)
            ->values()
            ->all();

        $attentionRequiredCount = $enabled->filter(
            fn (ConnectorAccount $account): bool => $account->connection_status === ConnectorAccountConnectionStatus::AttentionRequired,
        )->count();
        $temporarilyUnavailableCount = $enabled->filter(
            fn (ConnectorAccount $account): bool => $account->connection_status === ConnectorAccountConnectionStatus::TemporarilyUnavailable,
        )->count();
        $untestedCount = $enabled->filter(
            fn (ConnectorAccount $account): bool => $account->connection_status === ConnectorAccountConnectionStatus::Untested,
        )->count();
        $connectedCount = $enabled->filter(
            fn (ConnectorAccount $account): bool => $account->connection_status === ConnectorAccountConnectionStatus::Connected,
        )->count();

        if ($enabled->isEmpty()) {
            return new PlatformConnectionHealth(
                accountCount: $accounts->count(),
                enabledCount: 0,
                disabledCount: $disabled->count(),
                attentionRequiredCount: 0,
                temporarilyUnavailableCount: 0,
                untestedCount: 0,
                connectedCount: 0,
                connectionStatus: ConnectorAccountConnectionStatus::Disabled,
                enabledStatuses: [],
            );
        }

        $status = match (true) {
            $attentionRequiredCount > 0 => ConnectorAccountConnectionStatus::AttentionRequired,
            $temporarilyUnavailableCount > 0 => ConnectorAccountConnectionStatus::TemporarilyUnavailable,
            $untestedCount > 0 => ConnectorAccountConnectionStatus::Untested,
            default => ConnectorAccountConnectionStatus::Connected,
        };

        return new PlatformConnectionHealth(
            accountCount: $accounts->count(),
            enabledCount: $enabled->count(),
            disabledCount: $disabled->count(),
            attentionRequiredCount: $attentionRequiredCount,
            temporarilyUnavailableCount: $temporarilyUnavailableCount,
            untestedCount: $untestedCount,
            connectedCount: $connectedCount,
            connectionStatus: $status,
            enabledStatuses: $enabledStatuses,
        );
    }
}
