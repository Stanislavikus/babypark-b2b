<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AppServiceProviderSafeSyncBindingsTest extends TestCase
{
    #[Test]
    public function app_service_provider_preserves_readiness_handshake_binding_when_adding_simple_executor_binding(): void
    {
        $providerSource = file_get_contents(__DIR__.'/../../../../app/Providers/AppServiceProvider.php');
        $executorSource = file_get_contents(
            __DIR__.'/../../../../app/Support/Connectors/AdobePaaS/Command/AdobeProductSimpleCommandExecutor.php',
        );

        $this->assertIsString($providerSource);
        $this->assertIsString($executorSource);
        $this->assertStringContainsString(
            '$this->app->bind(AdobeSafeSyncHandshakeProbeCapability::class, AdobeSafeSyncHandshakeProbe::class);',
            $providerSource,
        );
        $this->assertStringContainsString(
            '$this->app->bind(',
            $providerSource,
        );
        $this->assertStringContainsString(
            'AdobeProductSimpleCommandExecutor::class,',
            $providerSource,
        );
        $this->assertStringContainsString(
            '$app->make(AdobeSafeSyncClient::class),',
            $providerSource,
        );
        $this->assertStringContainsString(
            'private readonly AdobeSafeSyncClient $safeSyncClient,',
            $executorSource,
        );
        $this->assertStringNotContainsString('?AdobeSafeSyncClient', $executorSource);
    }
}
