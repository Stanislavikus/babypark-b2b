<?php

namespace Tests\Unit\Connectors\Transport;

use App\Support\Connectors\Transport\ConnectorDestinationKind;
use App\Support\Connectors\Transport\ConnectorTransportDeadline;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use App\Support\Connectors\Transport\Curl\DefaultCurlClientFactory;
use App\Support\Connectors\Transport\Internal\ConnectorRequestSenderImpl;
use App\Support\Connectors\Transport\TransportFailureReason;
use App\Support\Connectors\Transport\ValidatedConnectorDestination;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Connectors\Transport\Support\FakeMonotonicClock;
use Tests\Unit\Connectors\Transport\Support\LocalHttpServer;
use Tests\Unit\Connectors\Transport\Support\TlsFixtureFactory;

class ConnectorTlsVerificationTest extends TestCase
{
    private string $tlsDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tlsDirectory = sys_get_temp_dir().'/connector-transport-tls-'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tlsDirectory)) {
            foreach (glob($this->tlsDirectory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tlsDirectory);
        }

        parent::tearDown();
    }

    #[Test]
    public function verifies_trust_chain_and_hostname_for_expected_host(): void
    {
        $hostname = 'tls-success.connector.test';
        $fixtures = TlsFixtureFactory::create($this->tlsDirectory, $hostname);

        $hostCapturePath = tempnam(sys_get_temp_dir(), 'connector_tls_host_');
        $this->assertIsString($hostCapturePath);

        $receivedHost = null;
        $server = new LocalHttpServer(
            function (string $request) use (&$receivedHost, $hostCapturePath): string {
                if (preg_match('/^Host:\s*([^\r\n]+)/mi', $request, $matches) === 1) {
                    $receivedHost = trim($matches[1]);
                    file_put_contents($hostCapturePath, $receivedHost);
                }

                return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK";
            },
            '127.0.0.1',
            true,
            $fixtures['serverCert'],
            $fixtures['serverKey'],
        );

        $sender = new ConnectorRequestSenderImpl(new DefaultCurlClientFactory, $fixtures['caBundle']);
        $destination = new ValidatedConnectorDestination(
            ConnectorDestinationKind::DnsHostname,
            'https',
            $hostname,
            $server->port(),
            $server->host(),
        );

        $server->serveOnceInBackground();
        $result = $sender->send(
            new Request('GET', 'https://'.$hostname.':'.$server->port().'/'),
            $destination,
            new ConnectorTransportLimits(2, 5, 1024),
            $this->deadline(),
        );
        $server->close();

        $this->assertSame(200, $result->statusCode);
        $capturedHost = is_file($hostCapturePath) ? file_get_contents($hostCapturePath) : $receivedHost;
        @unlink($hostCapturePath);
        $this->assertNotEmpty($capturedHost);
        $this->assertStringContainsString($hostname, (string) $capturedHost);
    }

    #[Test]
    public function fails_tls_verification_for_wrong_hostname_certificate(): void
    {
        $fixtures = TlsFixtureFactory::create($this->tlsDirectory, 'cert-host.connector.test');

        $server = new LocalHttpServer(
            fn (string $request): string => "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            '127.0.0.1',
            true,
            $fixtures['serverCert'],
            $fixtures['serverKey'],
        );

        $sender = new ConnectorRequestSenderImpl(new DefaultCurlClientFactory, $fixtures['caBundle']);
        $destination = new ValidatedConnectorDestination(
            ConnectorDestinationKind::DnsHostname,
            'https',
            'request-host.connector.test',
            $server->port(),
            $server->host(),
        );

        $server->serveOnceInBackground();

        try {
            $sender->send(
                new Request('GET', 'https://request-host.connector.test:'.$server->port().'/'),
                $destination,
                new ConnectorTransportLimits(2, 5, 1024),
                $this->deadline(),
            );
            $this->fail('Expected TLS verification failure.');
        } catch (ConnectorTransportException $exception) {
            $this->assertSame(TransportFailureReason::TlsVerificationFailed, $exception->reason);
        } finally {
            $server->close();
        }
    }

    private function deadline(): ConnectorTransportDeadline
    {
        $clock = new FakeMonotonicClock;

        return new ConnectorTransportDeadline($clock->nowNanoseconds() + 10_000_000_000, $clock);
    }
}
