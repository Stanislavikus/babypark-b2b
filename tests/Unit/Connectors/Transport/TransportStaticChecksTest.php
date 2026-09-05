<?php

namespace Tests\Unit\Connectors\Transport;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TransportStaticChecksTest extends TestCase
{
    #[Test]
    public function production_transport_namespace_has_no_forbidden_patterns(): void
    {
        $directory = base_path('app/Support/Connectors/Transport');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        $forbidden = [
            'pcntl_alarm',
            'pcntl_signal',
            'fromShellCommandline',
        ];

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertIsString($contents);
            foreach ($forbidden as $pattern) {
                $this->assertStringNotContainsString($pattern, $contents, $file->getPathname());
            }
        }
    }

    #[Test]
    public function production_code_outside_transport_does_not_import_internal_sender_or_validated_destination(): void
    {
        $appPath = base_path('app');
        $forbiddenUses = [
            'use App\\Support\\Connectors\\Transport\\Internal\\ConnectorRequestSenderImpl',
            'use App\\Support\\Connectors\\Transport\\ValidatedConnectorDestination',
        ];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appPath));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains($path, '/Support/Connectors/Transport/')) {
                continue;
            }

            if (str_contains($path, '/Providers/ConnectorTransportServiceProvider.php')) {
                continue;
            }

            $contents = file_get_contents($path);
            $this->assertIsString($contents);
            foreach ($forbiddenUses as $useStatement) {
                $this->assertStringNotContainsString($useStatement, $contents, $path);
            }
        }
    }

    #[Test]
    public function process_isolated_dns_resolver_uses_sigkill_not_stop(): void
    {
        $contents = file_get_contents(base_path('app/Support/Connectors/Transport/Dns/ProcessIsolatedDnsResolver.php'));
        $this->assertIsString($contents);
        $this->assertStringContainsString('signal(\SIGKILL)', $contents);
        $this->assertStringNotContainsString('->stop(', $contents);
    }

    #[Test]
    public function dns_child_process_is_created_with_argument_array(): void
    {
        $contents = file_get_contents(base_path('app/Support/Connectors/Transport/Dns/ProcessIsolatedDnsResolver.php'));
        $this->assertIsString($contents);
        $this->assertStringContainsString('PHP_BINARY', $contents);
        $this->assertStringContainsString('disableOutput()', $contents);
    }

    #[Test]
    public function symfony_process_is_used_for_dns_isolation(): void
    {
        $this->assertTrue(class_exists(Process::class));
        $this->assertStringContainsString(
            'Symfony\\Component\\Process\\Process',
            file_get_contents(base_path('app/Support/Connectors/Transport/Dns/ProcessIsolatedDnsResolver.php')) ?: ''
        );
    }
}
