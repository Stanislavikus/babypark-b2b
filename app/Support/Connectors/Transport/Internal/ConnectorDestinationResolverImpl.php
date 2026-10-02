<?php

namespace App\Support\Connectors\Transport\Internal;

use App\Support\Connectors\Transport\ConnectorDestinationKind;
use App\Support\Connectors\Transport\ConnectorDestinationResolver;
use App\Support\Connectors\Transport\ConnectorTransportDeadline;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\Dns\DnsResolver;
use App\Support\Connectors\Transport\Policy\IpAddressPolicy;
use App\Support\Connectors\Transport\TransportFailureReason;
use App\Support\Connectors\Transport\ValidatedConnectorDestination;
use App\Support\Connectors\Transport\Validation\ConnectorUriValidator;
use Psr\Http\Message\UriInterface;

final class ConnectorDestinationResolverImpl implements ConnectorDestinationResolver
{
    public function __construct(
        private readonly DnsResolver $dnsResolver,
        private readonly IpAddressPolicy $ipAddressPolicy = new IpAddressPolicy,
    ) {}

    public function resolveAndValidate(
        #[\SensitiveParameter] UriInterface $uri,
        ConnectorTransportDeadline $deadline,
    ): ValidatedConnectorDestination {
        try {
            $validated = ConnectorUriValidator::validate($uri);
        } catch (\InvalidArgumentException) {
            throw new ConnectorTransportException(TransportFailureReason::InvalidDestination);
        }

        if ($validated['isIpLiteral']) {
            $ip = $validated['normalizedIp'];
            if ($ip === null || ! $this->ipAddressPolicy->isGloballyReachable($ip)) {
                throw new ConnectorTransportException(TransportFailureReason::UnsafeDestination);
            }

            return new ValidatedConnectorDestination(
                kind: ConnectorDestinationKind::IpLiteral,
                scheme: $validated['scheme'],
                host: $validated['host'],
                port: $validated['port'],
                pinnedIp: null,
            );
        }

        if ($deadline->isExpired()) {
            throw new ConnectorTransportException(TransportFailureReason::Timeout);
        }

        $dnsResult = $this->dnsResolver->resolve($validated['host'], $deadline);

        if ($dnsResult->cleanupFailed) {
            throw new ConnectorTransportException(TransportFailureReason::ChildProcessCleanupFailed);
        }

        if ($dnsResult->timedOut) {
            throw new ConnectorTransportException(TransportFailureReason::Timeout);
        }

        if ($dnsResult->protocolFailed) {
            throw new ConnectorTransportException(TransportFailureReason::ChildProcessProtocolFailed);
        }

        if (! $dnsResult->success) {
            if ($dnsResult->errorReason !== null) {
                throw new ConnectorTransportException(TransportFailureReason::DnsResolutionFailed);
            }

            throw new ConnectorTransportException(TransportFailureReason::ChildProcessProtocolFailed);
        }

        try {
            $reachable = $this->ipAddressPolicy->filterAndSortReachable($dnsResult->addresses);
        } catch (\InvalidArgumentException) {
            throw new ConnectorTransportException(TransportFailureReason::UnsafeDestination);
        }

        if ($reachable === []) {
            throw new ConnectorTransportException(TransportFailureReason::UnsafeDestination);
        }

        return new ValidatedConnectorDestination(
            kind: ConnectorDestinationKind::DnsHostname,
            scheme: $validated['scheme'],
            host: $validated['host'],
            port: $validated['port'],
            pinnedIp: $reachable[0],
        );
    }
}
