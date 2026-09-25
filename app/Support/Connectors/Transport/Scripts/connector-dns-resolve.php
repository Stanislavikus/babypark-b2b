#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Support\Connectors\Transport\Dns\ConnectorDnsResolution;
use App\Support\Connectors\Transport\Dns\DnsProtocolConstants;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$stdin = stream_get_contents(STDIN, DnsProtocolConstants::MAX_STDIN_BYTES);
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

if (($request['version'] ?? null) !== DnsProtocolConstants::VERSION) {
    fwrite(STDERR, 'invalid version');
    exit(1);
}

$absoluteHostname = $request['hostname'] ?? '';
if (! is_string($absoluteHostname) || $absoluteHostname === '' || ! str_ends_with($absoluteHostname, '.')) {
    fwrite(STDERR, 'invalid hostname');
    exit(1);
}

$requestedHostname = ConnectorDnsResolution::normalizeHostname($absoluteHostname);

$result = ConnectorDnsResolution::resolveHostname(
    $requestedHostname,
    static fn (string $absoluteName): array|false => @dns_get_record($absoluteName, DNS_A | DNS_AAAA | DNS_CNAME),
);

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
