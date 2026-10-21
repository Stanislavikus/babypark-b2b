<?php

namespace App\Support\Connectors\Integrations;

use App\Enums\ConnectorAccountConnectionStatus;
use InvalidArgumentException;

/**
 * Page-specific merchant vocabulary for Інтеграції (§2).
 *
 * Maps real ConnectorAccountConnectionStatus cases — never invents a
 * not_connected enum value. Tier-1 absence uses notConnectedLabel().
 */
final class IntegrationsStatusVocabulary
{
    public function notConnectedLabel(): string
    {
        return __('connectors.ui.integrations.status.not_connected');
    }

    public function labelFor(ConnectorAccountConnectionStatus $status): string
    {
        return match ($status) {
            ConnectorAccountConnectionStatus::Untested => __('connectors.ui.integrations.status.untested'),
            ConnectorAccountConnectionStatus::Connected => __('connectors.ui.integrations.status.connected'),
            ConnectorAccountConnectionStatus::AttentionRequired => __('connectors.ui.integrations.status.attention_required'),
            ConnectorAccountConnectionStatus::TemporarilyUnavailable => __('connectors.ui.integrations.status.temporarily_unavailable'),
            ConnectorAccountConnectionStatus::Disabled => __('connectors.ui.integrations.status.disabled'),
        };
    }

    public function colorFor(?ConnectorAccountConnectionStatus $status): string
    {
        if ($status === null) {
            return 'gray';
        }

        return match ($status) {
            ConnectorAccountConnectionStatus::Connected => 'success',
            ConnectorAccountConnectionStatus::AttentionRequired => 'warning',
            ConnectorAccountConnectionStatus::TemporarilyUnavailable => 'gray',
            ConnectorAccountConnectionStatus::Untested,
            ConnectorAccountConnectionStatus::Disabled => 'gray',
        };
    }

    public function assertRealStatus(?ConnectorAccountConnectionStatus $status): void
    {
        if ($status === null) {
            return;
        }

        if (! in_array($status, ConnectorAccountConnectionStatus::cases(), true)) {
            throw new InvalidArgumentException('Unknown connection status.');
        }
    }
}
