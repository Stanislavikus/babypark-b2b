<?php

namespace App\Support\Connectors\Transport;

enum ConnectorDestinationKind
{
    case DnsHostname;
    case IpLiteral;
}
