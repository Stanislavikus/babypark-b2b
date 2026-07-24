#!/usr/bin/env php
<?php

declare(strict_types=1);

$payload = str_repeat('a', 70000);
fwrite(STDOUT, json_encode([
    'version' => 1,
    'status' => 'ok',
    'requested_hostname' => 'big.example.com',
    'cname_chain' => [],
    'terminal' => [
        'owner' => 'big.example.com',
        'addresses' => [['family' => 'ipv4', 'address' => '93.184.216.34']],
    ],
    'padding' => $payload,
], JSON_THROW_ON_ERROR));
