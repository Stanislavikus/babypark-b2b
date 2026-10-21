#!/usr/bin/env php
<?php

declare(strict_types=1);

$stdin = stream_get_contents(STDIN, 1024);
$request = json_decode($stdin ?: '{}', true);
$sleepSeconds = (int) ($request['sleep_seconds'] ?? 5);
sleep($sleepSeconds);
fwrite(STDOUT, json_encode([
    'version' => 1,
    'status' => 'ok',
    'requested_hostname' => 'slow.example.com',
    'cname_chain' => [],
    'terminal' => [
        'owner' => 'slow.example.com',
        'addresses' => [['family' => 'ipv4', 'address' => '93.184.216.34']],
    ],
], JSON_THROW_ON_ERROR));
