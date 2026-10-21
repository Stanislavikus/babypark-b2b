<?php

namespace App\Support\Connectors\Exceptions;

enum ConnectorDiscoverySourceResolutionReason: string
{
    case Missing = 'missing';
    case Ambiguous = 'ambiguous';
}
