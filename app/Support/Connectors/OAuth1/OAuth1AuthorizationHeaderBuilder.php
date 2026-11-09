<?php

namespace App\Support\Connectors\OAuth1;

final class OAuth1AuthorizationHeaderBuilder
{
    /**
     * Fixed parameter order matches the approved Phase A golden fixture.
     *
     * @var list<string>
     */
    private const PARAMETER_ORDER = [
        'oauth_consumer_key',
        'oauth_nonce',
        'oauth_signature',
        'oauth_signature_method',
        'oauth_timestamp',
        'oauth_token',
        'oauth_version',
    ];

    /**
     * @param  array<string, string>  $protocolParameters
     */
    public function build(array $protocolParameters): string
    {
        $parts = [];

        foreach (self::PARAMETER_ORDER as $name) {
            if (! array_key_exists($name, $protocolParameters)) {
                continue;
            }

            $parts[] = OAuth1PercentEncoder::encode($name)
                .'="'
                .$this->encodeHeaderValue($protocolParameters[$name])
                .'"';
        }

        return 'OAuth '.implode(', ', $parts);
    }

    private function encodeHeaderValue(string $value): string
    {
        return str_replace('%3D', '=', OAuth1PercentEncoder::encode($value));
    }
}
