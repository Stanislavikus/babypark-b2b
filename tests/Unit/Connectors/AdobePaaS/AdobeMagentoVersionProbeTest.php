<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\AdobeMagentoVersionProbe;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AdobeMagentoVersionProbeTest extends TestCase
{
    #[Test]
    public function it_reads_stock_magento_version_from_the_bounded_transport_seam(): void
    {
        $captured = (object) ['request' => null];

        $probe = new AdobeMagentoVersionProbe(new class($captured) implements ConnectorHttpTransport
        {
            public function __construct(private object $captured) {}

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->captured->request = $request;

                return new ConnectorHttpResult(200, [], 'Magento/2.4 (Community)');
            }
        });

        $result = $probe->probe($this->context('https://shop.example.com/base/path/'));

        $this->assertSame('Magento/2.4 (Community)', $result);
        $this->assertNotNull($captured->request);
        $this->assertSame('GET', $captured->request->request->getMethod());
        $this->assertSame('https://shop.example.com/base/path/magento_version', (string) $captured->request->request->getUri());
        $this->assertSame(5.0, $captured->request->limits->connectTimeoutSeconds);
        $this->assertSame(15.0, $captured->request->limits->totalTimeoutSeconds);
        $this->assertSame(4096, $captured->request->limits->maxResponseBodyBytes);
    }

    #[Test]
    public function it_rejects_malformed_or_unrecognized_success_payloads(): void
    {
        foreach ([
            '',
            '2.4.9',
            'Community 2.4',
            str_repeat('A', 121),
            "Magento/\x01",
        ] as $body) {
            $probe = new AdobeMagentoVersionProbe(new class($body) implements ConnectorHttpTransport
            {
                public function __construct(private string $body) {}

                public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
                {
                    return new ConnectorHttpResult(200, [], $this->body);
                }
            });

            $this->assertNull($probe->probe($this->context()), $body === '' ? '<empty>' : $body);
        }
    }

    #[Test]
    public function it_returns_null_for_non_success_responses_and_transport_failures(): void
    {
        $nonSuccess = new AdobeMagentoVersionProbe(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(404, [], 'Magento/2.4 (Community)');
            }
        });

        $transportFailure = new AdobeMagentoVersionProbe(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                throw new ConnectorTransportException(TransportFailureReason::Timeout);
            }
        });

        $this->assertNull($nonSuccess->probe($this->context()));
        $this->assertNull($transportFailure->probe($this->context()));
    }

    private function context(string $baseUrl = 'https://shop.example.com'): AdobePaaSRequestContext
    {
        return new AdobePaaSRequestContext(
            $baseUrl,
            'default',
            new OAuth1Credentials('ck', 'cs', 'at', 'as'),
        );
    }
}
