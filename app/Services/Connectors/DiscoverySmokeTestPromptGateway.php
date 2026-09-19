<?php

namespace App\Services\Connectors;

use App\Support\Connectors\OAuth1\OAuth1Credentials;

interface DiscoverySmokeTestPromptGateway
{
    public function askBaseUrl(): string;

    public function askStoreCode(): string;

    public function askTenantContext(): ?string;

    public function confirmReplaceCredentials(): bool;

    public function askOAuth1Credentials(): OAuth1Credentials;

    public function confirmWorkerRunning(): bool;
}
