<?php

namespace App\Support\Connectors\Transport\Dns;

use App\Support\Connectors\Transport\ConnectorTransportDeadline;

interface DnsResolver
{
    public function resolve(
        string $absoluteHostname,
        ConnectorTransportDeadline $deadline,
    ): DnsResolutionResult;
}
