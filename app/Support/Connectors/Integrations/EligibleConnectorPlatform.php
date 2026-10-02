<?php

namespace App\Support\Connectors\Integrations;

use App\Enums\ConnectorDefinitionStatus;

/**
 * Merchant-safe projection of a connector platform for the Інтеграції landing.
 *
 * Intentionally narrow: name/code/status only. Never expose ConnectorDefinition
 * internals (source_kind, endpoint_path, verification_status, …).
 */
final readonly class EligibleConnectorPlatform
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public ConnectorDefinitionStatus $status,
    ) {}

    public function isDeprecated(): bool
    {
        return $this->status === ConnectorDefinitionStatus::Deprecated;
    }

    public function allowsNewConnections(): bool
    {
        return $this->status === ConnectorDefinitionStatus::Active;
    }
}
