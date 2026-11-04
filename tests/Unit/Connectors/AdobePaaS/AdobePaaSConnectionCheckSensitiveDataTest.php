<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapabilityImpl;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckRequestFactory;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckResponseMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckTransportMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Connectors\Transport\AssertsTransportSecretsSafely;

class AdobePaaSConnectionCheckSensitiveDataTest extends TestCase
{
    use AssertsTransportSecretsSafely;

    private const CREDENTIAL_CANARY = 'CANARY_SECRET_MARKER_4B2A2B';

    #[Test]
    public function secrets_do_not_leak_through_capability_or_mapper_stack(): void
    {
        $canary = self::CREDENTIAL_CANARY;

        $context = new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: new OAuth1Credentials(
                'ck_'.self::CREDENTIAL_CANARY,
                'cs_'.self::CREDENTIAL_CANARY,
                'at_'.self::CREDENTIAL_CANARY,
                'ts_'.self::CREDENTIAL_CANARY,
            ),
        );

        $transport = new class($canary) implements ConnectorHttpTransport
        {
            public function __construct(private readonly string $canary) {}

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(401, [], 'oauth_problem=signature_invalid&secret='.$this->canary);
            }
        };

        $capability = new AdobePaaSConnectionCheckCapabilityImpl(
            new AdobePaaSConnectionCheckRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobePaaSConnectionCheckResponseMapper,
            new AdobePaaSConnectionCheckTransportMapper,
        );

        $result = $capability->checkConnection($context);

        $this->assertFalse($result->succeeded);
        $this->assertStringNotContainsString(self::CREDENTIAL_CANARY, (string) $result->technicalSummary());
        $this->assertStringNotContainsString(self::CREDENTIAL_CANARY, (string) $result->messageKey());
    }

    #[Test]
    public function transport_failure_result_does_not_leak_request_secrets(): void
    {
        $canary = self::CREDENTIAL_CANARY;

        $context = new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: new OAuth1Credentials(
                $canary,
                $canary,
                $canary,
                $canary,
            ),
        );

        $transport = new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                throw new ConnectorTransportException(TransportFailureReason::ConnectionFailed);
            }
        };

        $capability = new AdobePaaSConnectionCheckCapabilityImpl(
            new AdobePaaSConnectionCheckRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobePaaSConnectionCheckResponseMapper,
            new AdobePaaSConnectionCheckTransportMapper,
        );

        $result = $capability->checkConnection($context);

        $this->assertFalse($result->succeeded);
        $this->assertStringNotContainsString($canary, (string) $result->technicalSummary());
        $this->assertStringNotContainsString($canary, (string) $result->messageKey());
    }
}
