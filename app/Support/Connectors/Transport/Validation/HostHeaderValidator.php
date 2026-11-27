<?php

namespace App\Support\Connectors\Transport\Validation;

final class HostHeaderValidator
{
    public static function validate(
        ?string $hostHeader,
        string $uriHost,
        int $effectivePort,
        string $scheme,
    ): void {
        if ($hostHeader === null) {
            return;
        }

        $parts = array_map('trim', explode(',', $hostHeader));
        if (count($parts) !== 1) {
            throw new \InvalidArgumentException('multiple host headers');
        }

        $value = $parts[0];
        if ($value === '') {
            throw new \InvalidArgumentException('empty host header');
        }

        $headerHost = $value;
        $headerPort = null;

        if (str_starts_with($value, '[')) {
            $closing = strpos($value, ']');
            if ($closing === false) {
                throw new \InvalidArgumentException('invalid host header');
            }

            $headerHost = substr($value, 1, $closing - 1);
            $remainder = substr($value, $closing + 1);
            if ($remainder !== '') {
                if (! str_starts_with($remainder, ':')) {
                    throw new \InvalidArgumentException('invalid host header');
                }
                $headerPort = (int) substr($remainder, 1);
            }
        } elseif (substr_count($value, ':') === 1 && ! filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            [$headerHost, $portString] = explode(':', $value, 2);
            $headerPort = (int) $portString;
        }

        $normalizedHeaderHost = self::normalizeHost($headerHost);
        $normalizedUriHost = self::normalizeHost($uriHost);

        if ($normalizedHeaderHost !== $normalizedUriHost) {
            throw new \InvalidArgumentException('host mismatch');
        }

        if ($headerPort !== null && $headerPort !== $effectivePort) {
            throw new \InvalidArgumentException('port mismatch');
        }
    }

    private static function normalizeHost(string $host): string
    {
        $ip = IpLiteralGrammar::tryParse($host);
        if ($ip !== null) {
            return $ip;
        }

        return HostnameGrammar::normalize($host);
    }
}
