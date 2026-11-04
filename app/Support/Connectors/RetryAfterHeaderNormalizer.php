<?php

namespace App\Support\Connectors;

final class RetryAfterHeaderNormalizer
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public static function normalize(array $headers): ?int
    {
        $values = $headers['Retry-After'] ?? $headers['retry-after'] ?? null;

        if ($values === null || count($values) !== 1) {
            return null;
        }

        $value = $values[0];

        if ($value === '' || ! preg_match('/^\d+$/', $value)) {
            if (! preg_match('/[A-Za-z]/', $value)) {
                return null;
            }

            $timestamp = strtotime($value);

            if ($timestamp === false) {
                return null;
            }

            $now = microtime(true);

            if ($timestamp <= $now) {
                return null;
            }

            $delta = max(1, (int) ceil($timestamp - $now));

            return min($delta, 300);
        }

        return min((int) $value, 300);
    }
}
