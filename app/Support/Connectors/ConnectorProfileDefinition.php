<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorCapability;

final readonly class ConnectorProfileDefinition
{
    /**
     * @param  class-string<ConnectorAdapter>  $adapterClass
     * @param  class-string<ConnectorAccountSchema>  $accountSchemaClass
     * @param  list<ConnectorCapability>  $capabilities
     * @param  class-string|null  $previewCapabilityClass
     */
    public function __construct(
        public string $profileCode,
        public bool $enabled,
        public string $connectorDefinitionCode,
        public string $adapterClass,
        public string $accountSchemaClass,
        public array $capabilities,
        public ?string $previewCapabilityClass = null,
    ) {}

    public function supports(ConnectorCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
