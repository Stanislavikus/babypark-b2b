<?php

namespace App\Support\Connectors\Transport\Validation;

final class HostnameGrammar
{
    private const MAX_TOTAL_LENGTH = 253;

    private const MAX_LABEL_LENGTH = 63;

    public static function isValid(string $hostname): bool
    {
        if ($hostname === '' || strlen($hostname) > self::MAX_TOTAL_LENGTH) {
            return false;
        }

        if (str_ends_with($hostname, '.')) {
            return false;
        }

        $labels = explode('.', $hostname);
        if ($labels === []) {
            return false;
        }

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > self::MAX_LABEL_LENGTH) {
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

    public static function normalize(string $hostname): string
    {
        return strtolower(rtrim($hostname, '.'));
    }
}
