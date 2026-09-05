<?php

namespace App\Support\Connectors\Integrations;

use App\Enums\ConnectorAccountConnectionStatus;

/**
 * Tier-1 / Tier-2 platform health for Інтеграції.
 *
 * Tier-1 not-connected is represented by $connectionStatus === null
 * (absence of accounts) — never a fabricated ConnectorAccountConnectionStatus case.
 */
final readonly class PlatformConnectionHealth
{
    /**
     * @param  list<ConnectorAccountConnectionStatus>  $enabledStatuses
     */
    public function __construct(
        public int $accountCount,
        public int $enabledCount,
        public int $disabledCount,
        public int $attentionRequiredCount,
        public int $temporarilyUnavailableCount,
        public int $untestedCount,
        public int $connectedCount,
        public ?ConnectorAccountConnectionStatus $connectionStatus,
        /** @var list<ConnectorAccountConnectionStatus> */
        public array $enabledStatuses = [],
    ) {}

    public function isNotConnected(): bool
    {
        return $this->accountCount === 0;
    }

    public function isSingleAccount(): bool
    {
        return $this->accountCount === 1;
    }

    public function isMultiAccount(): bool
    {
        return $this->accountCount > 1;
    }
}
