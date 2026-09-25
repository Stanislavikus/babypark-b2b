<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckTransportMapper;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TimeoutPhase;
use App\Support\Connectors\Transport\TransportFailureReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobePaaSConnectionCheckTransportMapperTest extends TestCase
{
    private AdobePaaSConnectionCheckTransportMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new AdobePaaSConnectionCheckTransportMapper;
    }

    #[Test]
    #[DataProvider('transportFailureProvider')]
    public function maps_transport_failure_reason(
        TransportFailureReason $reason,
        ?TimeoutPhase $timeoutPhase,
        ConnectorConnectionCheckErrorCode $expectedCode,
        string $expectedMessageKey,
    ): void {
        $result = $this->mapper->map(new ConnectorTransportException($reason, $timeoutPhase));

        $this->assertFalse($result->succeeded);
        $this->assertNull($result->httpStatus);
        $this->assertSame($expectedCode, $result->errorCode);
        $this->assertSame($timeoutPhase, $result->timeoutPhase);
        $this->assertSame($expectedMessageKey, $result->messageKey());
    }

    /**
     * @return iterable<string, array{
     *     0: TransportFailureReason,
     *     1: ?TimeoutPhase,
     *     2: ConnectorConnectionCheckErrorCode,
     *     3: string
     * }>
     */
    public static function transportFailureProvider(): iterable
    {
        yield 'InvalidDestination' => [
            TransportFailureReason::InvalidDestination,
            null,
            ConnectorConnectionCheckErrorCode::TransportInvalidDestination,
            'connectors.errors.invalid_or_unsupported_endpoint',
        ];

        yield 'UnsafeDestination' => [
            TransportFailureReason::UnsafeDestination,
            null,
            ConnectorConnectionCheckErrorCode::TransportUnsafeDestination,
            'connectors.errors.invalid_or_unsupported_endpoint',
        ];

        yield 'DnsResolutionFailed' => [
            TransportFailureReason::DnsResolutionFailed,
            null,
            ConnectorConnectionCheckErrorCode::TransportDnsResolutionFailed,
            'connectors.errors.network_unavailable',
        ];

        yield 'Timeout' => [
            TransportFailureReason::Timeout,
            TimeoutPhase::Connect,
            ConnectorConnectionCheckErrorCode::TransportTimeout,
            'connectors.errors.network_unavailable',
        ];

        yield 'ConnectionFailed' => [
            TransportFailureReason::ConnectionFailed,
            null,
            ConnectorConnectionCheckErrorCode::TransportConnectionFailed,
            'connectors.errors.network_unavailable',
        ];

        yield 'TlsVerificationFailed' => [
            TransportFailureReason::TlsVerificationFailed,
            null,
            ConnectorConnectionCheckErrorCode::TransportTlsVerificationFailed,
            'connectors.errors.tls_verification_failed',
        ];

        yield 'ResponseSizeExceeded' => [
            TransportFailureReason::ResponseSizeExceeded,
            null,
            ConnectorConnectionCheckErrorCode::TransportResponseSizeExceeded,
            'connectors.errors.unexpected_response',
        ];

        yield 'ChildProcessProtocolFailed' => [
            TransportFailureReason::ChildProcessProtocolFailed,
            null,
            ConnectorConnectionCheckErrorCode::TransportChildProcessProtocolFailed,
            'connectors.errors.connection_check_failed',
        ];

        yield 'ChildProcessCleanupFailed' => [
            TransportFailureReason::ChildProcessCleanupFailed,
            null,
            ConnectorConnectionCheckErrorCode::TransportChildProcessCleanupFailed,
            'connectors.errors.connection_check_failed',
        ];

        yield 'OtherTransportFailure' => [
            TransportFailureReason::OtherTransportFailure,
            null,
            ConnectorConnectionCheckErrorCode::TransportOtherFailure,
            'connectors.errors.connection_check_failed',
        ];
    }
}
