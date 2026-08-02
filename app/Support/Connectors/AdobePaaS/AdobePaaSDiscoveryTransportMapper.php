<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;

final class AdobePaaSDiscoveryTransportMapper
{
    public function map(ConnectorTransportException $exception): ConnectorDiscoveryAttemptResult
    {
        $errorCode = match ($exception->reason) {
            TransportFailureReason::InvalidDestination => ConnectorDiscoveryRunErrorCode::TransportInvalidDestination,
            TransportFailureReason::UnsafeDestination => ConnectorDiscoveryRunErrorCode::TransportUnsafeDestination,
            TransportFailureReason::DnsResolutionFailed => ConnectorDiscoveryRunErrorCode::TransportDnsResolutionFailed,
            TransportFailureReason::Timeout => ConnectorDiscoveryRunErrorCode::TransportTimeout,
            TransportFailureReason::ConnectionFailed => ConnectorDiscoveryRunErrorCode::TransportConnectionFailed,
            TransportFailureReason::TlsVerificationFailed => ConnectorDiscoveryRunErrorCode::TransportTlsVerificationFailed,
            TransportFailureReason::ResponseSizeExceeded => ConnectorDiscoveryRunErrorCode::TransportResponseSizeExceeded,
            TransportFailureReason::ChildProcessProtocolFailed => ConnectorDiscoveryRunErrorCode::TransportChildProcessProtocolFailed,
            TransportFailureReason::ChildProcessCleanupFailed => ConnectorDiscoveryRunErrorCode::TransportChildProcessCleanupFailed,
            TransportFailureReason::OtherTransportFailure => ConnectorDiscoveryRunErrorCode::TransportOtherFailure,
        };

        return ConnectorDiscoveryAttemptResult::transportFailure($errorCode, $exception->timeoutPhase);
    }
}
