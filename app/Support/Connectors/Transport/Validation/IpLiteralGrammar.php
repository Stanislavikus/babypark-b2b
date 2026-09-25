<?php

namespace App\Support\Connectors\Transport\Validation;

final class IpLiteralGrammar
{
    public static function tryParse(string $host): ?string
    {
        if (str_contains($host, '%')) {
            return null;
        }

        if (self::isAmbiguousNumericForm($host)) {
            return null;
        }

        if (preg_match('/^\d+$/', $host) === 1) {
            return null;
        }

        if (str_starts_with($host, '0x') || str_contains($host, '0x')) {
            return null;
        }

        if (self::looksLikeIpv4($host)) {
            return self::parseCanonicalIpv4($host);
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $inner = substr($host, 1, -1);

            return self::parseIpv6($inner);
        }

        if (str_contains($host, ':')) {
            return self::parseIpv6($host);
        }

        return null;
    }

    private static function isAmbiguousNumericForm(string $host): bool
    {
        if (preg_match('/^\d+$/', $host) === 1) {
            return true;
        }

        if (preg_match('/^0x[0-9a-fA-F]+$/', $host) === 1) {
            return true;
        }

        $parts = explode('.', $host);
        if (count($parts) < 2 || count($parts) > 4) {
            return false;
        }

        foreach ($parts as $part) {
            if (preg_match('/^0[0-7]+$/', $part) === 1) {
                return true;
            }

            if (preg_match('/^0x[0-9a-fA-F]+$/i', $part) === 1) {
                return true;
            }
        }

        if (count($parts) < 4) {
            return true;
        }

        return false;
    }

    private static function looksLikeIpv4(string $host): bool
    {
        return preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host) === 1;
    }

    private static function parseCanonicalIpv4(string $host): ?string
    {
        $parts = explode('.', $host);
        if (count($parts) !== 4) {
            return null;
        }

        $octets = [];
        foreach ($parts as $part) {
            if ($part === '' || ! ctype_digit($part)) {
                return null;
            }

            if (strlen($part) > 1 && $part[0] === '0') {
                return null;
            }

            $value = (int) $part;
            if ($value < 0 || $value > 255) {
                return null;
            }

            $octets[] = $value;
        }

        $packed = pack('C4', ...$octets);
        $normalized = inet_ntop($packed);

        return $normalized === false ? null : $normalized;
    }

    private static function parseIpv6(string $host): ?string
    {
        if (str_contains($host, '%')) {
            return null;
        }

        $packed = @inet_pton($host);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $normalized = inet_ntop($packed);

        return $normalized === false ? null : $normalized;
    }
}
