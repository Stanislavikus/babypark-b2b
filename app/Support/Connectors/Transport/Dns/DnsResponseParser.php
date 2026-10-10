<?php

namespace App\Support\Connectors\Transport\Dns;

use App\Support\Connectors\Transport\Policy\IpAddressPolicy;
use App\Support\Connectors\Transport\Validation\HostnameGrammar;

final class DnsResponseParser
{
    public function __construct(
        private readonly IpAddressPolicy $ipAddressPolicy = new IpAddressPolicy,
    ) {}

    /**
     * @return array{success: true, requestedHostname: string, cnameChain: list<array{owner: string, target: string}>, terminalOwner: string, addresses: list<string>}|array{success: false, dnsError: ?string, protocolFailed: true}
     */
    public function parse(string $stdout, string $originalHostname): array
    {
        try {
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
        }

        if (! is_array($decoded)) {
            return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
        }

        $allowedFields = array_merge(
            ['version', 'status'],
            $this->allowedFieldsForStatus($decoded['status'] ?? ''),
        );
        foreach (array_keys($decoded) as $field) {
            if (! in_array($field, $allowedFields, true)) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }
        }

        if (($decoded['version'] ?? null) !== DnsProtocolConstants::VERSION) {
            return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
        }

        $status = $decoded['status'] ?? null;
        if ($status === 'error') {
            $reason = $decoded['reason'] ?? null;
            if (! is_string($reason) || ! in_array($reason, DnsProtocolConstants::ERROR_REASONS, true)) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }

            if (isset($decoded['requested_hostname']) || isset($decoded['cname_chain']) || isset($decoded['terminal'])) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }

            return ['success' => false, 'dnsError' => $reason, 'protocolFailed' => false];
        }

        if ($status !== 'ok') {
            return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
        }

        if (isset($decoded['reason'])) {
            return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
        }

        return $this->parseSuccess($decoded, $originalHostname);
    }

    /**
     * @return list<string>
     */
    private function allowedFieldsForStatus(mixed $status): array
    {
        return match ($status) {
            'ok' => ['requested_hostname', 'cname_chain', 'terminal'],
            'error' => ['reason'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{success: true, requestedHostname: string, cnameChain: list<array{owner: string, target: string}>, terminalOwner: string, addresses: list<string>}|array{success: false, dnsError: ?string, protocolFailed: true}
     */
    private function parseSuccess(array $decoded, string $originalHostname): array
    {
        $requestedHostname = $decoded['requested_hostname'] ?? null;
        if (! is_string($requestedHostname) || HostnameGrammar::normalize($requestedHostname) !== HostnameGrammar::normalize($originalHostname)) {
            return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
        }

        $cnameChain = $decoded['cname_chain'] ?? null;
        if (! is_array($cnameChain) || count($cnameChain) > DnsProtocolConstants::MAX_CNAME_DEPTH) {
            return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
        }

        $seen = [HostnameGrammar::normalize($requestedHostname) => true];
        $previous = HostnameGrammar::normalize($requestedHostname);
        $parsedChain = [];

        foreach ($cnameChain as $index => $hop) {
            if (! is_array($hop)) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }

            $allowedHopFields = ['owner', 'target'];
            foreach (array_keys($hop) as $field) {
                if (! in_array($field, $allowedHopFields, true)) {
                    return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
                }
            }

            $owner = $hop['owner'] ?? null;
            $target = $hop['target'] ?? null;
            if (! is_string($owner) || ! is_string($target)) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }

            $normalizedOwner = HostnameGrammar::normalize($owner);
            $normalizedTarget = HostnameGrammar::normalize($target);

            if ($normalizedOwner !== $previous) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }

            if (! HostnameGrammar::isValid($normalizedTarget)) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }

            if (isset($seen[$normalizedTarget])) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }

            $seen[$normalizedTarget] = true;
            $parsedChain[] = ['owner' => $normalizedOwner, 'target' => $normalizedTarget];
            $previous = $normalizedTarget;
        }

        $terminal = $decoded['terminal'] ?? null;
        if (! is_array($terminal)) {
            return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
        }

        $allowedTerminalFields = ['owner', 'addresses'];
        foreach (array_keys($terminal) as $field) {
            if (! in_array($field, $allowedTerminalFields, true)) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }
        }

        $terminalOwner = $terminal['owner'] ?? null;
        if (! is_string($terminalOwner) || HostnameGrammar::normalize($terminalOwner) !== $previous) {
            return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
        }

        $addressesRaw = $terminal['addresses'] ?? null;
        if (! is_array($addressesRaw) || $addressesRaw === [] || count($addressesRaw) > DnsProtocolConstants::MAX_TERMINAL_ADDRESSES) {
            return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
        }

        if (count($parsedChain) + count($addressesRaw) > DnsProtocolConstants::MAX_TOTAL_DNS_RECORDS) {
            return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
        }

        $addresses = [];
        $packedSeen = [];

        foreach ($addressesRaw as $entry) {
            if (! is_array($entry)) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }

            $allowedAddressFields = ['family', 'address'];
            foreach (array_keys($entry) as $field) {
                if (! in_array($field, $allowedAddressFields, true)) {
                    return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
                }
            }

            $family = $entry['family'] ?? null;
            $address = $entry['address'] ?? null;
            if (! is_string($family) || ! is_string($address)) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }

            $normalized = $this->ipAddressPolicy->normalize($address);
            if ($normalized === null) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }

            $packed = inet_pton($normalized);
            if ($packed === false) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }

            $expectedFamily = strlen($packed) === 4 ? 'ipv4' : 'ipv6';
            if ($family !== $expectedFamily) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }

            $hex = bin2hex($packed);
            if (isset($packedSeen[$hex])) {
                return ['success' => false, 'dnsError' => null, 'protocolFailed' => true];
            }

            $packedSeen[$hex] = true;
            $addresses[] = $normalized;
        }

        return [
            'success' => true,
            'requestedHostname' => HostnameGrammar::normalize($requestedHostname),
            'cnameChain' => $parsedChain,
            'terminalOwner' => HostnameGrammar::normalize($terminalOwner),
            'addresses' => $addresses,
        ];
    }
}
