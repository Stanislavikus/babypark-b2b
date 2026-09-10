<?php

namespace Tests\Unit\Connectors\Transport;

use App\Support\Connectors\Transport\ConnectorDestinationKind;
use App\Support\Connectors\Transport\ConnectorTransportDeadline;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use App\Support\Connectors\Transport\Curl\CurlClientFactory;
use App\Support\Connectors\Transport\Curl\DefaultCurlClientFactory;
use App\Support\Connectors\Transport\DestinationRequestMismatch;
use App\Support\Connectors\Transport\Internal\ConnectorRequestSenderImpl;
use App\Support\Connectors\Transport\TransportFailureReason;
use App\Support\Connectors\Transport\ValidatedConnectorDestination;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;
use Tests\Unit\Connectors\Transport\Support\FakeMonotonicClock;
use Tests\Unit\Connectors\Transport\Support\LocalHttpServer;
use Tests\Unit\Connectors\Transport\Support\SlowChunkedBodyHttpServer;

class ConnectorRequestSenderTest extends TestCase
{
    use AssertsTransportSecretsSafely;

    /** @var array<string, string|null> */
    private array $originalProxyEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->originalProxyEnv as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function sends_pinned_request_to_local_server_without_resolve_for_ip_literal(): void
    {
        $server = new LocalHttpServer(function (string $request): string {
            return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK";
        });

        $sender = new ConnectorRequestSenderImpl(new DefaultCurlClientFactory, true);
        $destination = new ValidatedConnectorDestination(
            ConnectorDestinationKind::IpLiteral,
            'http',
            $server->host(),
            $server->port(),
            null,
        );

        $server->serveOnceInBackground();
        $result = $sender->send(
            new Request('GET', 'http://'.$server->host().':'.$server->port().'/'),
            $destination,
            new ConnectorTransportLimits(1, 2, 1024),
            $this->deadline(),
        );

        $server->close();
        $this->assertSame(200, $result->statusCode);
        $this->assertSame('OK', $result->body);
        $this->assertArrayNotHasKey(\CURLOPT_RESOLVE, $sender->lastCurlOptions());
    }

    #[Test]
    public function uses_curlp_resolve_for_dns_hostname_destination(): void
    {
        $server = new LocalHttpServer(function (string $request): string {
            return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK";
        });

        $sender = new ConnectorRequestSenderImpl(new DefaultCurlClientFactory, true);
        $destination = new ValidatedConnectorDestination(
            ConnectorDestinationKind::DnsHostname,
            'http',
            'pinned-host.example.com',
            $server->port(),
            $server->host(),
        );

        $server->serveOnceInBackground();
        $result = $sender->send(
            new Request('GET', 'http://pinned-host.example.com:'.$server->port().'/'),
            $destination,
            new ConnectorTransportLimits(1, 2, 1024),
            $this->deadline(),
        );

        $server->close();
        $this->assertSame(200, $result->statusCode);
        $this->assertArrayHasKey(\CURLOPT_RESOLVE, $sender->lastCurlOptions());
    }

    #[Test]
    public function ignores_proxy_environment_variables(): void
    {
        $this->setProxyEnv('ALL_PROXY', 'tcp://127.0.0.1:1');
        $this->setProxyEnv('HTTPS_PROXY', 'tcp://127.0.0.1:1');
        $this->setProxyEnv('NO_PROXY', '');

        $server = new LocalHttpServer(function (string $request): string {
            return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK";
        });

        $sender = new ConnectorRequestSenderImpl(new DefaultCurlClientFactory, true);
        $destination = new ValidatedConnectorDestination(
            ConnectorDestinationKind::DnsHostname,
            'http',
            'proxy-test.example.com',
            $server->port(),
            $server->host(),
        );

        $server->serveOnceInBackground();
        $result = $sender->send(
            new Request('GET', 'http://proxy-test.example.com:'.$server->port().'/'),
            $destination,
            new ConnectorTransportLimits(1, 2, 1024),
            $this->deadline(),
        );

        $server->close();
        $this->assertSame(200, $result->statusCode);
        $this->assertSame('', $sender->lastCurlOptions()[\CURLOPT_PROXY]);
    }

    #[Test]
    public function curl_options_include_nosignal(): void
    {
        $server = new LocalHttpServer(fn (string $request): string => "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $sender = new ConnectorRequestSenderImpl(new DefaultCurlClientFactory, true);
        $destination = new ValidatedConnectorDestination(
            ConnectorDestinationKind::IpLiteral,
            'http',
            $server->host(),
            $server->port(),
            null,
        );

        $server->serveOnceInBackground();
        $sender->send(
            new Request('GET', 'http://'.$server->host().':'.$server->port().'/'),
            $destination,
            new ConnectorTransportLimits(1, 2, 1024),
            $this->deadline(),
        );
        $server->close();

        $this->assertSame(1, $sender->lastCurlOptions()[\CURLOPT_NOSIGNAL]);
    }

    #[Test]
    public function destination_request_mismatch_throws_without_connecting(): void
    {
        $sender = new ConnectorRequestSenderImpl(new DefaultCurlClientFactory, true);
        $destination = new ValidatedConnectorDestination(
            ConnectorDestinationKind::DnsHostname,
            'https',
            'one.example.com',
            443,
            '93.184.216.34',
        );

        $this->expectException(DestinationRequestMismatch::class);
        $sender->send(
            new Request('GET', 'https://other.example.com/'),
            $destination,
            new ConnectorTransportLimits(1, 2, 1024),
            $this->deadline(),
        );
    }

    #[Test]
    public function returns_non_success_status_codes_as_results(): void
    {
        $server = new LocalHttpServer(fn (string $request): string => "HTTP/1.1 302 Found\r\nLocation: https://elsewhere.example/\r\nContent-Length: 0\r\n\r\n");
        $sender = new ConnectorRequestSenderImpl(new DefaultCurlClientFactory, true);
        $destination = new ValidatedConnectorDestination(
            ConnectorDestinationKind::IpLiteral,
            'http',
            $server->host(),
            $server->port(),
            null,
        );

        $server->serveOnceInBackground();
        $result = $sender->send(
            new Request('GET', 'http://'.$server->host().':'.$server->port().'/'),
            $destination,
            new ConnectorTransportLimits(1, 2, 1024),
            $this->deadline(),
        );
        $server->close();

        $this->assertSame(302, $result->statusCode);
        $this->assertSame('https://elsewhere.example/', $result->headers['Location'][0] ?? null);
    }

    #[Test]
    public function enforces_decoded_gzip_response_size_limit(): void
    {
        $payload = str_repeat('A', 200);
        $compressed = gzencode($payload);
        $this->assertIsString($compressed);

        $server = new LocalHttpServer(function (string $request) use ($compressed): string {
            return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Encoding: gzip\r\nContent-Length: "
                .strlen($compressed)."\r\n\r\n".$compressed;
        });

        $sender = new ConnectorRequestSenderImpl(new DefaultCurlClientFactory, true);
        $destination = new ValidatedConnectorDestination(
            ConnectorDestinationKind::IpLiteral,
            'http',
            $server->host(),
            $server->port(),
            null,
        );

        $server->serveOnceInBackground();

        try {
            $sender->send(
                new Request('GET', 'http://'.$server->host().':'.$server->port().'/'),
                $destination,
                new ConnectorTransportLimits(1, 2, 50),
                $this->deadline(),
            );
            $this->fail('Expected response size failure.');
        } catch (ConnectorTransportException $exception) {
            $this->assertSame(TransportFailureReason::ResponseSizeExceeded, $exception->reason);
        } finally {
            $server->close();
        }
    }

    #[Test]
    public function accepts_response_body_at_exact_max_byte_limit(): void
    {
        $maxResponseBodyBytes = 1024;
        $body = str_repeat('B', $maxResponseBodyBytes);

        $server = new LocalHttpServer(function (string $request) use ($body): string {
            return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: "
                .strlen($body)."\r\n\r\n".$body;
        });

        $sender = new ConnectorRequestSenderImpl(new DefaultCurlClientFactory, true);
        $destination = new ValidatedConnectorDestination(
            ConnectorDestinationKind::IpLiteral,
            'http',
            $server->host(),
            $server->port(),
            null,
        );

        $server->serveOnceInBackground();
        $result = $sender->send(
            new Request('GET', 'http://'.$server->host().':'.$server->port().'/'),
            $destination,
            new ConnectorTransportLimits(1, 2, $maxResponseBodyBytes),
            $this->deadline(),
        );
        $server->close();

        $this->assertSame(200, $result->statusCode);
        $this->assertSame($body, $result->body);
    }

    #[Test]
    public function aborts_oversized_response_during_transfer_before_server_finishes(): void
    {
        $totalBodyBytes = 256 * 1024;
        $byteLimit = 8 * 1024;

        $server = new SlowChunkedBodyHttpServer;
        $server->serveInBackground(
            totalBodyBytes: $totalBodyBytes,
            chunkSize: 4096,
            interChunkDelayMicroseconds: 50_000,
        );

        $sender = new ConnectorRequestSenderImpl(new DefaultCurlClientFactory, true);
        $destination = new ValidatedConnectorDestination(
            ConnectorDestinationKind::IpLiteral,
            'http',
            $server->host(),
            $server->port(),
            null,
        );

        try {
            $sender->send(
                new Request('GET', 'http://'.$server->host().':'.$server->port().'/'),
                $destination,
                new ConnectorTransportLimits(2, 10, $byteLimit),
                $this->deadline(),
            );
            $this->fail('Expected response size failure.');
        } catch (ConnectorTransportException $exception) {
            $this->assertSame(TransportFailureReason::ResponseSizeExceeded, $exception->reason);
        } finally {
            $server->waitForHandler();
            $server->close();
        }

        $this->assertFalse($server->completionMarkerExists());
        $this->assertLessThan($totalBodyBytes, $server->bytesSuccessfullyWritten());
    }

    #[Test]
    public function sender_canary_does_not_leak_sensitive_request_data(): void
    {
        $server = new LocalHttpServer(fn (string $request): string => '');
        $host = $server->host();
        $port = $server->port();
        $server->close();

        $sender = new ConnectorRequestSenderImpl(new DefaultCurlClientFactory, true);
        $destination = new ValidatedConnectorDestination(
            ConnectorDestinationKind::IpLiteral,
            'http',
            $host,
            $port,
            null,
        );

        try {
            $sender->send(
                new Request(
                    'POST',
                    'http://'.$host.':'.$port.'/?'.$this->canaryQuery(),
                    ['Authorization' => $this->canaryHeader(), 'Host' => $host.':'.$port],
                    $this->canaryBody(),
                ),
                $destination,
                new ConnectorTransportLimits(1, 2, 1024),
                $this->deadline(),
            );
            $this->fail('Expected sender-level transport failure.');
        } catch (ConnectorTransportException $exception) {
            $this->assertExceptionDoesNotLeakCanary($exception);
        }
    }

    #[Test]
    public function uses_guzzle_send_not_psr18(): void
    {
        $server = new LocalHttpServer(fn (string $request): string => "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $factory = new RecordingCurlClientFactory;
        $sender = new ConnectorRequestSenderImpl($factory, true);
        $destination = new ValidatedConnectorDestination(
            ConnectorDestinationKind::IpLiteral,
            'http',
            $server->host(),
            $server->port(),
            null,
        );

        $server->serveOnceInBackground();
        $sender->send(
            new Request('GET', 'http://'.$server->host().':'.$server->port().'/'),
            $destination,
            new ConnectorTransportLimits(1, 2, 1024),
            $this->deadline(),
        );
        $server->close();

        $this->assertTrue($factory->usedSend);
        $this->assertFalse($factory->usedSendRequest);
    }

    private function deadline(): ConnectorTransportDeadline
    {
        $clock = new FakeMonotonicClock;

        return new ConnectorTransportDeadline($clock->nowNanoseconds() + 5_000_000_000, $clock);
    }

    private function setProxyEnv(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->originalProxyEnv)) {
            $current = getenv($key);
            $this->originalProxyEnv[$key] = $current === false ? null : $current;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

final class RecordingCurlClientFactory implements CurlClientFactory
{
    public bool $usedSend = false;

    public bool $usedSendRequest = false;

    public function create(array $defaultOptions): Client
    {
        return new RecordingGuzzleClient($this, $defaultOptions);
    }

    public function isCurlAvailable(): bool
    {
        return true;
    }
}

final class RecordingGuzzleClient extends Client
{
    public function __construct(
        private readonly RecordingCurlClientFactory $factory,
        array $config,
    ) {
        parent::__construct($config);
    }

    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        $this->factory->usedSend = true;

        return parent::send($request, $options);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->factory->usedSendRequest = true;

        return parent::sendRequest($request);
    }
}
