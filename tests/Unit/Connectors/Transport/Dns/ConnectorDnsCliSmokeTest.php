<?php

namespace Tests\Unit\Connectors\Transport\Dns;

use App\Support\Connectors\Transport\Dns\ConnectorDnsResolution;
use App\Support\Connectors\Transport\Dns\DnsProtocolConstants;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConnectorDnsCliSmokeTest extends TestCase
{
    private string $scriptPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scriptPath = base_path('app/Support/Connectors/Transport/Scripts/connector-dns-resolve.php');
    }

    #[Test]
    public function cli_rejects_malformed_json_without_unexpected_stderr(): void
    {
        $process = new Process([PHP_BINARY, $this->scriptPath]);
        $process->setInput('{not-json');
        $process->run();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertNotSame('', $process->getErrorOutput());
    }

    #[Test]
    public function cli_rejects_oversized_stdin_without_unexpected_stderr(): void
    {
        $oversized = json_encode([
            'version' => DnsProtocolConstants::VERSION,
            'hostname' => str_repeat('a', 1200).'.example.com.',
        ], JSON_THROW_ON_ERROR);

        $process = new Process([PHP_BINARY, $this->scriptPath]);
        $process->setInput($oversized);
        $process->run();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertNotSame('', $process->getErrorOutput());
    }

    #[Test]
    public function cli_rejects_unknown_request_field_without_dns_lookup(): void
    {
        $process = new Process([PHP_BINARY, $this->scriptPath]);
        $process->setInput(json_encode([
            'version' => DnsProtocolConstants::VERSION,
            'hostname' => 'api.example.com.',
            'extra' => true,
        ], JSON_THROW_ON_ERROR));
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertSame('unknown field', $process->getErrorOutput());
    }

    #[Test]
    public function production_execution_path_serializes_injected_lookup_result(): void
    {
        $stdin = json_encode([
            'version' => DnsProtocolConstants::VERSION,
            'hostname' => 'public.example.com.',
        ], JSON_THROW_ON_ERROR);

        $expected = ConnectorDnsResolution::resolveHostname(
            'public.example.com',
            static fn (string $absoluteName): array => [
                ['type' => 'A', 'host' => 'public.example.com', 'ip' => '93.184.216.34'],
            ],
        );

        $this->assertSame('ok', $expected['status']);
        $this->assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($expected, JSON_THROW_ON_ERROR));
    }
}
