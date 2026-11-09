<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;

final class AdobePaaSConnectionCheckTransportMapper
{
    public function map(ConnectorTransportException $exception): ConnectorConnectionCheckResult
    {
        $errorCode = match ($exception->reason) {
            TransportFailureReason::InvalidDestination => ConnectorConnectionCheckErrorCode::TransportInvalidDestination,
            TransportFailureReason::UnsafeDestination => ConnectorConnectionCheckErrorCode::TransportUnsafeDestination,
            TransportFailureReason::DnsResolutionFailed => ConnectorConnectionCheckErrorCode::TransportDnsResolutionFailed,
            TransportFailureReason::Timeout => ConnectorConnectionCheckErrorCode::TransportTimeout,
            TransportFailureReason::ConnectionFailed => ConnectorConnectionCheckErrorCode::TransportConnectionFailed,
            TransportFailureReason::TlsVerificationFailed => ConnectorConnectionCheckErrorCode::TransportTlsVerificationFailed,
            TransportFailureReason::ResponseSizeExceeded => ConnectorConnectionCheckErrorCode::TransportResponseSizeExceeded,
            TransportFailureReason::ChildProcessProtocolFailed => ConnectorConnectionCheckErrorCode::TransportChildProcessProtocolFailed,
            TransportFailureReason::ChildProcessCleanupFailed => ConnectorConnectionCheckErrorCode::TransportChildProcessCleanupFailed,
            TransportFailureReason::OtherTransportFailure => ConnectorConnectionCheckErrorCode::TransportOtherFailure,
        };

        return ConnectorConnectionCheckResult::transportFailure($errorCode, $exception->timeoutPhase);
    }
}
