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
     * @param  class-string|null  $liveCapabilityClass
     * @param  class-string|null  $fieldOptionMappingValidatorClass
     */
    public function __construct(
        public string $profileCode,
        public bool $enabled,
        public string $connectorDefinitionCode,
        public string $adapterClass,
        public string $accountSchemaClass,
        public array $capabilities,
        public ?string $previewCapabilityClass = null,
        public ?string $liveCapabilityClass = null,
        public ?string $fieldOptionMappingValidatorClass = null,
    ) {}

    public function supports(ConnectorCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
