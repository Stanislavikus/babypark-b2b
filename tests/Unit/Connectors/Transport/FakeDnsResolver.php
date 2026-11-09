<?php

namespace Tests\Unit\Connectors\Transport;

use App\Support\Connectors\Transport\ConnectorTransportDeadline;
use App\Support\Connectors\Transport\Dns\DnsResolutionResult;
use App\Support\Connectors\Transport\Dns\DnsResolver;

/**
 * @implements array<string, DnsResolutionResult>
 */
final class FakeDnsResolver implements DnsResolver
{
    /**
     * @param  array<string, DnsResolutionResult>  $responses
     */
    public function __construct(private array $responses) {}

    public function resolve(string $absoluteHostname, ConnectorTransportDeadline $deadline): DnsResolutionResult
    {
        $normalized = strtolower(rtrim($absoluteHostname, '.'));

        return $this->responses[$normalized]
            ?? DnsResolutionResult::dnsError('lookup_failed');
    }
}
