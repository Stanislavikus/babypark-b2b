<?php

namespace App\Support\Connectors\Integrations;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Models\ConnectorAccount;

/**
 * Presentation DTO for one platform card on Інтеграції.
 */
final readonly class PlatformIntegrationCard
{
    public const ACTION_CONNECT = 'connect';

    public const ACTION_OPEN = 'open';

    public const ACTION_NONE = 'none';

    /**
     * @param  list<ConnectorAccount>  $accounts
     */
    public function __construct(
        public EligibleConnectorPlatform $platform,
        public PlatformConnectionHealth $health,
        public string $statusLabel,
        public string $statusColor,
        public string $secondaryLine,
        public ?string $runtimeOverlayLabel,
        public string $primaryAction,
        public ?string $primaryActionUrl,
        public ?string $primaryActionLabel,
        public ?string $secondaryActionHint,
        public array $accounts,
        public ?ConnectorAccount $singleAccount,
        public bool $canCreate,
        public bool $setupAvailable,
    ) {}

    public function accountCount(): int
    {
        return $this->health->accountCount;
    }

    public function connectionStatus(): ?ConnectorAccountConnectionStatus
    {
        return $this->health->connectionStatus;
    }
}
