<?php

namespace App\Services\Pricing\Resolution;

enum PriceResolutionStatus: string
{
    case Resolved = 'resolved';
    case Unavailable = 'unavailable';
    case ConfigurationError = 'configuration_error';
}
