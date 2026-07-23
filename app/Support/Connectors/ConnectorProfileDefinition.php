<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorCapability;

final readonly class ConnectorProfileDefinition
{
    /**
     * @param  class-string<ConnectorAdapter>  $adapterClass
     * @param  list<ConnectorCapability>  $capabilities
     */
    public function __construct(
        public string $profileCode,
        public bool $enabled,
        public string $adapterClass,
        public array $capabilities,
    ) {}

    public function supports(ConnectorCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
