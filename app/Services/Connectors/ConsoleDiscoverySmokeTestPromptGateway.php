<?php

namespace App\Services\Connectors;

use App\Support\Connectors\OAuth1\OAuth1Credentials;
use Illuminate\Console\Command;

final class ConsoleDiscoverySmokeTestPromptGateway implements DiscoverySmokeTestPromptGateway
{
    public function __construct(
        private readonly Command $command,
    ) {}

    public function askBaseUrl(): string
    {
        return (string) $this->command->ask('Magento base URL (e.g. https://magento.example.com)');
    }

    public function askStoreCode(): string
    {
        return (string) $this->command->ask('Store code', 'default');
    }

    public function askTenantContext(): ?string
    {
        $value = $this->command->ask('Tenant context (optional, press Enter to skip)');

        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    public function confirmReplaceCredentials(): bool
    {
        return $this->command->confirm('Replace credentials for the matched account?', false);
    }

    public function askOAuth1Credentials(): OAuth1Credentials
    {
        return new OAuth1Credentials(
            consumerKey: (string) $this->command->secret('Consumer Key'),
            consumerSecret: (string) $this->command->secret('Consumer Secret'),
            accessToken: (string) $this->command->secret('Access Token'),
            accessTokenSecret: (string) $this->command->secret('Access Token Secret'),
        );
    }

    public function confirmWorkerRunning(): bool
    {
        return $this->command->confirm('Worker is running — continue?', false);
    }
}
