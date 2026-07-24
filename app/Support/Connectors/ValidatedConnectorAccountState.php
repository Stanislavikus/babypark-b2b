<?php

namespace App\Support\Connectors;

final readonly class ValidatedConnectorAccountState
{
    public function __construct(
        public string $baseUrl,
        public string $storeCode,
        public ?string $tenantContext,
        public array $settings,
        public ?string $name = null,
    ) {}
}
