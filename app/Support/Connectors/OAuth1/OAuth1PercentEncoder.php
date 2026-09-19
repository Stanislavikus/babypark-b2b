<?php

namespace App\Support\Connectors\OAuth1;

final class OAuth1PercentEncoder
{
    public static function encode(string $value): string
    {
        return rawurlencode($value);
    }
}
