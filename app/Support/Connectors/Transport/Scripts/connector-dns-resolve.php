#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Minimal DNS resolver child process for connector transport.
 * Reads protocol-v1 JSON from stdin, writes JSON to stdout.
 */
const MAX_CNAME_DEPTH = 8;
const MAX_TERMINAL_ADDRESSES = 64;
const MAX_TOTAL_DNS_RECORDS = 64;

function emitError(string $reason): never
{
    fwrite(STDOUT, json_encode([
        'version' => 1,
        'status' => 'error',
        'reason' => $reason,
    ], JSON_THROW_ON_ERROR));
    exit(0);
}

function emitOk(string $requestedHostname, array $cnameChain, string $terminalOwner, array $addresses): never
{
    fwrite(STDOUT, json_encode([
        'version' => 1,
        'status' => 'ok',
        'requested_hostname' => $requestedHostname,
        'cname_chain' => $cnameChain,
        'terminal' => [
            'owner' => $terminalOwner,
            'addresses' => $addresses,
        ],
    ], JSON_THROW_ON_ERROR));
    exit(0);
}

function normalizeHostname(string $hostname): string
{
    return strtolower(rtrim($hostname, '.'));
}

function isValidHostname(string $hostname): bool
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

function parseAddress(string $ip): ?array
{
    if (str_contains($ip, '%')) {
        return null;
    }

    $packed = @inet_pton($ip);
    if ($packed === false) {
        return null;
    }

    if (strlen($packed) === 4) {
        return ['family' => 'ipv4', 'address' => inet_ntop($packed)];
    }

    if (strlen($packed) === 16) {
        return ['family' => 'ipv6', 'address' => inet_ntop($packed)];
    }

    return null;
}

$stdin = stream_get_contents(STDIN, 1024);
if ($stdin === false || $stdin === '') {
    fwrite(STDERR, 'empty stdin');
    exit(1);
}

try {
    $request = json_decode($stdin, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    fwrite(STDERR, 'invalid json');
    exit(1);
}

if (! is_array($request)) {
    fwrite(STDERR, 'invalid request');
    exit(1);
}

$allowedRequestFields = ['version', 'hostname'];
foreach (array_keys($request) as $field) {
    if (! in_array($field, $allowedRequestFields, true)) {
        fwrite(STDERR, 'unknown field');
        exit(1);
    }
}

if (($request['version'] ?? null) !== 1) {
    fwrite(STDERR, 'invalid version');
    exit(1);
}

$absoluteHostname = $request['hostname'] ?? '';
if (! is_string($absoluteHostname) || $absoluteHostname === '' || ! str_ends_with($absoluteHostname, '.')) {
    fwrite(STDERR, 'invalid hostname');
    exit(1);
}

$requestedHostname = normalizeHostname($absoluteHostname);
if (! isValidHostname($requestedHostname)) {
    emitError('invalid_record');
}

$cnameChain = [];
$seen = [$requestedHostname => true];
$current = $requestedHostname;

for ($depth = 0; $depth <= MAX_CNAME_DEPTH; $depth++) {
    $records = @dns_get_record($current.'.', DNS_A | DNS_AAAA | DNS_CNAME);
    if ($records === false || $records === []) {
        if ($depth === 0) {
            emitError('lookup_failed');
        }
        emitError('no_terminal_address');
    }

    $cnames = [];
    $addresses = [];

    foreach ($records as $record) {
        $type = $record['type'] ?? '';
        if ($type === 'CNAME') {
            $cnames[] = normalizeHostname((string) ($record['target'] ?? ''));
        } elseif ($type === 'A' || $type === 'AAAA') {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            $parsed = parseAddress($ip);
            if ($parsed !== null) {
                $addresses[] = $parsed;
            }
        }
    }

    if ($cnames !== [] && $addresses !== []) {
        emitError('cname_with_address_records');
    }

    if (count($cnames) > 1) {
        $unique = array_unique($cnames);
        if (count($unique) > 1) {
            emitError('multiple_cname_targets');
        }
    }

    if ($cnames !== []) {
        $target = $cnames[0];
        if (! isValidHostname($target)) {
            emitError('invalid_record');
        }

        if (isset($seen[$target])) {
            emitError('loop_detected');
        }

        if (count($cnameChain) >= MAX_CNAME_DEPTH) {
            emitError('depth_exceeded');
        }

        $cnameChain[] = ['owner' => $current, 'target' => $target];
        $seen[$target] = true;
        $current = $target;

        continue;
    }

    if ($addresses === []) {
        emitError('no_terminal_address');
    }

    $uniqueAddresses = [];
    foreach ($addresses as $address) {
        $packed = inet_pton($address['address']);
        if ($packed === false) {
            emitError('invalid_record');
        }
        $uniqueAddresses[bin2hex($packed)] = $address;
    }

    $addressList = array_values($uniqueAddresses);
    if (count($addressList) > MAX_TERMINAL_ADDRESSES) {
        emitError('invalid_record');
    }

    if (count($cnameChain) + count($addressList) > MAX_TOTAL_DNS_RECORDS) {
        emitError('invalid_record');
    }

    emitOk($requestedHostname, $cnameChain, $current, $addressList);
}

emitError('depth_exceeded');
