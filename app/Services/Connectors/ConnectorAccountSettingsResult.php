<?php

namespace App\Services\Connectors;

final readonly class ConnectorAccountSettingsResult
{
    public function __construct(
        public string $id,
        public string $connectorDefinitionId,
        public string $authProfile,
        public string $baseUrl,
        public string $storeCode,
        public ?string $tenantContext,
        public array $settings,
        public bool $isEnabled,
        public bool $hasCredentials,
    ) {}
}
