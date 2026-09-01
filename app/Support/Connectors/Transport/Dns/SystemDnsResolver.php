<?php

namespace App\Support\Connectors\Transport\Dns;

use App\Support\Connectors\Transport\ConnectorTransportDeadline;
use App\Support\Connectors\Transport\Validation\HostnameGrammar;

/**
 * Cross-platform DNS resolver used when the Linux-only process-isolated resolver
 * cannot be constructed (e.g. Windows local dev/test environment).
 *
 * Security note: SSRF safety is still enforced by ConnectorDestinationResolverImpl
 * via IpAddressPolicy filtering of the returned addresses.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $absoluteHostname, ConnectorTransportDeadline $deadline): DnsResolutionResult
    {
        if ($deadline->isExpired()) {
            return DnsResolutionResult::timeout();
        }

        $normalized = HostnameGrammar::normalize($absoluteHostname);

        /**
         * dns_get_record() can emit warnings (e.g. if DNS is misconfigured).
         * We fail closed to `dnsError(...)` rather than leaking warnings.
         *
         * @var array<int, array<string, mixed>>|false $records
         */
        $records = @dns_get_record($normalized, DNS_A | DNS_AAAA);

        if ($records === false) {
            return DnsResolutionResult::dnsError('lookup_failed');
        }

        /** @var list<string> $addresses */
        $addresses = [];

        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $addresses[] = $record['ip'];
            }

            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        $addresses = array_values(array_unique($addresses));

        if ($addresses === []) {
            return DnsResolutionResult::dnsError('no_addresses');
        }

        return DnsResolutionResult::ok(
            requestedHostname: $normalized,
            cnameChain: [],
            terminalOwner: $normalized,
            addresses: $addresses,
        );
    }
}

