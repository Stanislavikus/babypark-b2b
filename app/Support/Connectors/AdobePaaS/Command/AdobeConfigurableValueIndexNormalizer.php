<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final class AdobeConfigurableValueIndexNormalizer
{
    public static function normalize(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        if (! preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
            return null;
        }

        $parsed = (int) $value;

        if ((string) $parsed !== $value) {
            return null;
        }

        return $parsed;
    }
}
