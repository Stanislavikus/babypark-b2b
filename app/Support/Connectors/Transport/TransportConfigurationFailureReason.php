<?php

namespace App\Support\Connectors\Transport;

enum TransportConfigurationFailureReason
{
    case UnsupportedPlatform;
    case CurlUnavailable;
    case InvalidCaBundle;
}
