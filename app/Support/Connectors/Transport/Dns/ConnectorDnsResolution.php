<?php

namespace App\Support\Connectors\Transport\Dns;

/**
 * Framework-free DNS resolution logic for connector transport protocol-v1.
 */
final class ConnectorDnsResolution
{
    public const MAX_CNAME_DEPTH = 8;

    public const MAX_TERMINAL_ADDRESSES = 64;

    public const MAX_TOTAL_DNS_RECORDS = 64;

    /**
     * @param  callable(string): array|false  $lookup  Receives absolute DNS name (with trailing dot).
     * @return array<string, mixed>
     */
    public static function resolveHostname(string $requestedHostname, callable $lookup): array
    {
        $normalized = self::normalizeHostname($requestedHostname);
        if (! self::isValidHostname($normalized)) {
            return self::errorEnvelope('invalid_record');
        }

        $cnameChain = [];
        $seen = [$normalized => true];
        $current = $normalized;

        for ($depth = 0; $depth <= self::MAX_CNAME_DEPTH; $depth++) {
            $records = $lookup($current.'.');
            if ($records === false || $records === []) {
                return self::errorEnvelope($depth === 0 ? 'lookup_failed' : 'no_terminal_address');
            }

            $hop = self::interpretRecords($records);
            if ($hop['type'] === 'error') {
                return self::errorEnvelope($hop['reason']);
            }

            if ($hop['type'] === 'cname') {
                $target = $hop['target'];
                if (! self::isValidHostname($target)) {
                    return self::errorEnvelope('invalid_record');
                }

                if (isset($seen[$target])) {
                    return self::errorEnvelope('loop_detected');
                }

                if (count($cnameChain) >= self::MAX_CNAME_DEPTH) {
                    return self::errorEnvelope('depth_exceeded');
                }

                $cnameChain[] = ['owner' => $current, 'target' => $target];
                $seen[$target] = true;
                $current = $target;

                continue;
            }

            $addressList = $hop['addresses'];
            if (count($addressList) > self::MAX_TERMINAL_ADDRESSES) {
                return self::errorEnvelope('invalid_record');
            }

            if (count($cnameChain) + count($addressList) > self::MAX_TOTAL_DNS_RECORDS) {
                return self::errorEnvelope('invalid_record');
            }

            return self::okEnvelope($normalized, $cnameChain, $current, $addressList);
        }

        return self::errorEnvelope('depth_exceeded');
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array{type: 'cname', target: string}|array{type: 'terminal', addresses: list<array{family: string, address: string}>}|array{type: 'error', reason: string}
     */
    public static function interpretRecords(array $records): array
    {
        $cnames = [];
        $addresses = [];

        foreach ($records as $record) {
            $type = $record['type'] ?? '';
            if ($type === 'CNAME') {
                $cnames[] = self::normalizeHostname((string) ($record['target'] ?? ''));
            } elseif ($type === 'A' || $type === 'AAAA') {
                $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                $parsed = self::parseAddress($ip);
                if ($parsed !== null) {
                    $addresses[] = $parsed;
                }
            }
        }

        if ($cnames !== [] && $addresses !== []) {
            return ['type' => 'error', 'reason' => 'cname_with_address_records'];
        }

        if (count($cnames) > 1) {
            $unique = array_unique($cnames);
            if (count($unique) > 1) {
                return ['type' => 'error', 'reason' => 'multiple_cname_targets'];
            }
        }

        if ($cnames !== []) {
            return ['type' => 'cname', 'target' => $cnames[0]];
        }

        if ($addresses === []) {
            return ['type' => 'error', 'reason' => 'no_terminal_address'];
        }

        $validated = self::validateTerminalAddresses($addresses);
        if ($validated === null) {
            return ['type' => 'error', 'reason' => 'invalid_record'];
        }

        return ['type' => 'terminal', 'addresses' => $validated];
    }

    /**
     * @param  list<array{family: string, address: string}>  $addresses
     * @return list<array{family: string, address: string}>|null
     */
    public static function validateTerminalAddresses(array $addresses): ?array
    {
        $packedSeen = [];
        $validated = [];

        foreach ($addresses as $address) {
            $packed = @inet_pton($address['address']);
            if ($packed === false) {
                return null;
            }

            $hex = bin2hex($packed);
            if (isset($packedSeen[$hex])) {
                return null;
            }

            $packedSeen[$hex] = true;
            $validated[] = $address;
        }

        return $validated;
    }

    /**
     * @param  list<array{owner: string, target: string}>  $cnameChain
     * @param  list<array{family: string, address: string}>  $addresses
     * @return array<string, mixed>
     */
    private static function okEnvelope(
        string $requestedHostname,
        array $cnameChain,
        string $terminalOwner,
        array $addresses,
    ): array {
        return [
            'version' => 1,
            'status' => 'ok',
            'requested_hostname' => $requestedHostname,
            'cname_chain' => $cnameChain,
            'terminal' => [
                'owner' => $terminalOwner,
                'addresses' => $addresses,
            ],
        ];
    }

    /**
     * @return array{version: int, status: 'error', reason: string}
     */
    private static function errorEnvelope(string $reason): array
    {
        return [
            'version' => 1,
            'status' => 'error',
            'reason' => $reason,
        ];
    }

    public static function normalizeHostname(string $hostname): string
    {
        return strtolower(rtrim($hostname, '.'));
    }

    public static function isValidHostname(string $hostname): bool
    {
        if ($hostname === '' || strlen($hostname) > 253 || str_ends_with($hostname, '.')) {
            return false;
        }

        $labels = explode('.', $hostname);
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }
            if (str_starts_with($label, 'xn--')) {
                return false;
            }
            if ($label[0] === '-' || $label[strlen($label) - 1] === '-') {
                return false;
            }
            if (! preg_match('/^[a-zA-Z0-9-]+$/', $label)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{family: string, address: string}|null
     */
    public static function parseAddress(string $ip): ?array
    {
        if (str_contains($ip, '%')) {
            return null;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 4) {
            $normalized = inet_ntop($packed);

            return $normalized === false ? null : ['family' => 'ipv4', 'address' => $normalized];
        }

        if (strlen($packed) === 16) {
            $normalized = inet_ntop($packed);

            return $normalized === false ? null : ['family' => 'ipv6', 'address' => $normalized];
        }

        return null;
    }
}
