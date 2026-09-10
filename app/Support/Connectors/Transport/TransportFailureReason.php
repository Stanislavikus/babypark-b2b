<?php

namespace App\Support\Connectors\Transport;

enum TransportFailureReason
{
    case InvalidDestination;
    case DnsResolutionFailed;
    case UnsafeDestination;
    case Timeout;
    case ConnectionFailed;
    case TlsVerificationFailed;
    case ResponseSizeExceeded;
    case ChildProcessProtocolFailed;
    case ChildProcessCleanupFailed;
    case OtherTransportFailure;
}
