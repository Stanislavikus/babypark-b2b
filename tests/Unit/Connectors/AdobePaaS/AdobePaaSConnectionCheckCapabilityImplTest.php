<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapabilityImpl;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckRequestFactory;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckResponseMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckTransportMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\DestinationRequestMismatch;
use App\Support\Connectors\Transport\TransportConfigurationException;
use App\Support\Connectors\Transport\TransportConfigurationFailureReason;
use App\Support\Connectors\Transport\TransportFailureReason;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class AdobePaaSConnectionCheckCapabilityImplTest extends TestCase
{
    #[Test]
    public function sends_baseline_request_with_b12_limits_and_then_optionally_probes_catalog_total_count(): void
    {
        $context = new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: new OAuth1Credentials('ck_test', 'cs_test', 'at_test', 'ts_test'),
        );

        $requestFactory = new AdobePaaSConnectionCheckRequestFactory(new OAuth1RequestSigner);
        $referenceRequest = $requestFactory->build(
            $context,
            new OAuth1SigningContext('fixednonce00000001', 1_700_000_000),
        );
        $referenceProductsRequest = $requestFactory->buildProductsSearch(
            $context,
            new OAuth1SigningContext('fixednonce00000002', 1_700_000_000),
            ['pageSize' => 1],
        );

        $transport = new class($referenceRequest) implements ConnectorHttpTransport
        {
            public int $sendCount = 0;

            /** @var list<ConnectorOutboundRequest> */
            public array $captured = [];

            public function __construct(private readonly RequestInterface $referenceRequest) {}

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->sendCount++;
                $this->captured[] = $request;

                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [],
                    'search_criteria' => new \stdClass,
                    'total_count' => 0,
                ], JSON_THROW_ON_ERROR));
            }
        };

        $capability = new AdobePaaSConnectionCheckCapabilityImpl(
            $requestFactory,
            $transport,
            new AdobePaaSConnectionCheckResponseMapper,
            new AdobePaaSConnectionCheckTransportMapper,
        );

        $result = $capability->checkConnection($context);

        $this->assertTrue($result->succeeded);
        $this->assertSame(2, $transport->sendCount);
        $this->assertCount(2, $transport->captured);
        $this->assertSame('GET', $transport->captured[0]->request->getMethod());
        $this->assertSame((string) $referenceRequest->getUri(), (string) $transport->captured[0]->request->getUri());
        $this->assertSame((string) $referenceProductsRequest->getUri(), (string) $transport->captured[1]->request->getUri());
        $this->assertStringContainsString('oauth_consumer_key="ck_test"', $transport->captured[0]->request->getHeaderLine('Authorization'));
        $this->assertNotSame(
            $referenceRequest->getHeaderLine('Authorization'),
            $transport->captured[0]->request->getHeaderLine('Authorization'),
        );
        $this->assertSame(['catalog_total_count' => 0], $result->safeMessageParameters());
        $this->assertSame(5.0, $transport->captured[0]->limits->connectTimeoutSeconds);
        $this->assertSame(30.0, $transport->captured[0]->limits->totalTimeoutSeconds);
        $this->assertSame(256 * 1024, $transport->captured[0]->limits->maxResponseBodyBytes);
        $this->assertSame(5.0, $transport->captured[1]->limits->connectTimeoutSeconds);
        $this->assertSame(30.0, $transport->captured[1]->limits->totalTimeoutSeconds);
        $this->assertSame(256 * 1024, $transport->captured[1]->limits->maxResponseBodyBytes);
    }

    #[Test]
    public function destination_request_mismatch_propagates_uncaught(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                throw new DestinationRequestMismatch;
            }
        };

        $capability = $this->capabilityWithTransport($transport);

        $this->expectException(DestinationRequestMismatch::class);

        $capability->checkConnection($this->sampleContext());
    }

    #[Test]
    public function transport_configuration_exception_propagates_uncaught(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                throw new TransportConfigurationException(TransportConfigurationFailureReason::CurlUnavailable);
            }
        };

        $capability = $this->capabilityWithTransport($transport);

        $this->expectException(TransportConfigurationException::class);

        $capability->checkConnection($this->sampleContext());
    }

    #[Test]
    public function transport_exception_maps_to_result(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                throw new ConnectorTransportException(TransportFailureReason::Timeout);
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->checkConnection($this->sampleContext());

        $this->assertFalse($result->succeeded);
        $this->assertNull($result->httpStatus);
    }

    private function capabilityWithTransport(ConnectorHttpTransport $transport): AdobePaaSConnectionCheckCapabilityImpl
    {
        return new AdobePaaSConnectionCheckCapabilityImpl(
            new AdobePaaSConnectionCheckRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobePaaSConnectionCheckResponseMapper,
            new AdobePaaSConnectionCheckTransportMapper,
        );
    }

    private function sampleContext(): AdobePaaSRequestContext
    {
        return new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: new OAuth1Credentials('ck_test', 'cs_test', 'at_test', 'ts_test'),
        );
    }
}
