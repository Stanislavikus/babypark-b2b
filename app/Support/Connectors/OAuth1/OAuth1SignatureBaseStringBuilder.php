<?php

namespace App\Support\Connectors\OAuth1;

final class OAuth1SignatureBaseStringBuilder
{
    public function build(string $method, string $baseStringUri, string $normalizedParameterString): string
    {
        return implode('&', [
            OAuth1PercentEncoder::encode(strtoupper($method)),
            OAuth1PercentEncoder::encode($baseStringUri),
            OAuth1PercentEncoder::encode($normalizedParameterString),
        ]);
    }
}
