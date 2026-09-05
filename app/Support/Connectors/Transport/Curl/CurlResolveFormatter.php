<?php

namespace App\Support\Connectors\Transport\Curl;

final class CurlResolveFormatter
{
    public static function format(string $host, int $port, string $ip): string
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            throw new \InvalidArgumentException('Invalid IP address.');
        }

        if (strlen($packed) === 16) {
            return sprintf('%s:%d:[%s]', $host, $port, $ip);
        }

        return sprintf('%s:%d:%s', $host, $port, $ip);
    }
}
