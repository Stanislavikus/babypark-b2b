#!/usr/bin/env php
<?php

declare(strict_types=1);

$stdin = stream_get_contents(STDIN, 1024);
$request = json_decode($stdin ?: '{}', true);
$hostname = rtrim((string) ($request['hostname'] ?? ''), '.');

$responses = [
    'cname-public.example.com' => [
        'status' => 'ok',
        'requested_hostname' => 'cname-public.example.com',
        'cname_chain' => [['owner' => 'cname-public.example.com', 'target' => 'edge.example.net']],
        'terminal' => [
            'owner' => 'edge.example.net',
            'addresses' => [['family' => 'ipv4', 'address' => '93.184.216.34']],
        ],
    ],
    'cname-unsafe.example.com' => [
        'status' => 'ok',
        'requested_hostname' => 'cname-unsafe.example.com',
        'cname_chain' => [['owner' => 'cname-unsafe.example.com', 'target' => 'internal.example.net']],
        'terminal' => [
            'owner' => 'internal.example.net',
            'addresses' => [['family' => 'ipv4', 'address' => '10.0.0.1']],
        ],
    ],
    'public.example.com' => [
        'status' => 'ok',
        'requested_hostname' => 'public.example.com',
        'cname_chain' => [],
        'terminal' => [
            'owner' => 'public.example.com',
            'addresses' => [['family' => 'ipv4', 'address' => '93.184.216.34']],
        ],
    ],
];

if (! isset($responses[$hostname])) {
    fwrite(STDOUT, json_encode([
        'version' => 1,
        'status' => 'error',
        'reason' => 'lookup_failed',
    ], JSON_THROW_ON_ERROR));
    exit(0);
}

fwrite(STDOUT, json_encode(['version' => 1] + $responses[$hostname], JSON_THROW_ON_ERROR));
