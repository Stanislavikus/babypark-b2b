<?php

namespace Tests\Unit\Connectors\AdobePaaS\SafeSync;

use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckResponseMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckTransportMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncContract;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncHandshakeParser;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncHandshakeProbe;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncHandshakeProbeResult;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncRequestFactory;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TimeoutPhase;
use App\Support\Connectors\Transport\TransportFailureReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AdobeSafeSyncHandshakeProbeTest extends TestCase
{
    #[Test]
    public function malformed_and_non_object_success_responses_are_structured_schema_failures(): void
    {
        foreach (['{', '[]'] as $body) {
            $result = $this->probeReturning(new ConnectorHttpResult(200, [], $body));
            $this->assertNull($result->handshake);
            $this->assertSame(ConnectorConnectionCheckErrorCode::AdobeUnexpectedResponse, $result->connectionResult->errorCode);
            $this->assertSame(200, $result->connectionResult->httpStatus);
        }
    }

    #[Test]
    public function timeout_and_oversized_transport_failures_remain_structured_and_never_return_a_handshake(): void
    {
        foreach ([
            new ConnectorTransportException(TransportFailureReason::Timeout, TimeoutPhase::Transfer),
            new ConnectorTransportException(TransportFailureReason::ResponseSizeExceeded),
        ] as $exception) {
            $transport = new class($exception) implements ConnectorHttpTransport
            {
                public function __construct(private ConnectorTransportException $exception) {}

                public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
                {
                    throw $this->exception;
                }
            };
            $result = $this->probe($transport)->probe($this->context());
            $this->assertNull($result->handshake);
            $this->assertFalse($result->connectionResult->succeeded);
        }
    }

    #[Test]
    public function valid_payload_tolerates_unknown_top_level_fields_and_preserves_bounded_limit(): void
    {
        $captured = (object) ['request' => null];
        $transport = new class($captured) implements ConnectorHttpTransport
        {
            public function __construct(private object $captured) {}

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->captured->request = $request;

                return new ConnectorHttpResult(200, [], json_encode([
                    'contract_version' => AdobeSafeSyncContract::CONTRACT_VERSION,
                    'module_version' => '0.2.1',
                    'supported_operation_families' => [AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY],
                    'application_version' => '2.4.7-p1',
                    'php_version' => '8.3.10',
                    'future_field' => ['ignored' => true],
                ], JSON_THROW_ON_ERROR));
            }
        };

        $result = $this->probe($transport)->probe($this->context());
        $this->assertNotNull($result->handshake);
        $this->assertSame('2.4.7-p1', $result->handshake?->applicationVersion);
        $this->assertSame('8.3.10', $result->handshake?->phpVersion);
        $this->assertSame(AdobeSafeSyncContract::HANDSHAKE_MAX_RESPONSE_BYTES, $captured->request->limits->maxResponseBodyBytes);
    }

    #[Test]
    public function older_payload_without_optional_fields_remains_accepted(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'contract_version' => AdobeSafeSyncContract::CONTRACT_VERSION,
                    'module_version' => '0.2.1',
                    'supported_operation_families' => [AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY],
                ], JSON_THROW_ON_ERROR));
            }
        };

        $result = $this->probe($transport)->probe($this->context());
        $this->assertNotNull($result->handshake);
        $this->assertNull($result->handshake?->applicationVersion);
        $this->assertNull($result->handshake?->phpVersion);
    }

    private function probeReturning(ConnectorHttpResult $response): AdobeSafeSyncHandshakeProbeResult
    {
        return $this->probe(new class($response) implements ConnectorHttpTransport
        {
            public function __construct(private ConnectorHttpResult $response) {}

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return $this->response;
            }
        })->probe($this->context());
    }

    private function probe(ConnectorHttpTransport $transport): AdobeSafeSyncHandshakeProbe
    {
        return new AdobeSafeSyncHandshakeProbe(
            new AdobeSafeSyncRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobeSafeSyncHandshakeParser,
            new AdobePaaSConnectionCheckResponseMapper,
            new AdobePaaSConnectionCheckTransportMapper,
        );
    }

    private function context(): AdobePaaSRequestContext
    {
        return new AdobePaaSRequestContext('https://shop.example.com', 'default', new OAuth1Credentials('ck', 'cs', 'at', 'as'));
    }
}
