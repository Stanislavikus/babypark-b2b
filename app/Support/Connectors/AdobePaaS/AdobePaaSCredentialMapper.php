<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\OAuth1\OAuth1Credentials;

final class AdobePaaSCredentialMapper
{
    /**
     * @return array<string, string>
     */
    public static function toStorageArray(OAuth1Credentials $credentials): array
    {
        return [
            'consumer_key' => $credentials->consumerKey,
            'consumer_secret' => $credentials->consumerSecret,
            'access_token' => $credentials->accessToken,
            'access_token_secret' => $credentials->accessTokenSecret,
        ];
    }

    public static function fromStorageArray(array $credentials): OAuth1Credentials
    {
        foreach ([
            'consumer_key',
            'consumer_secret',
            'access_token',
            'access_token_secret',
        ] as $key) {
            if (! array_key_exists($key, $credentials) || $credentials[$key] === '') {
                throw new Exceptions\IncompleteAdobePaaSCredentialsException(
                    'Connector account does not have a complete Adobe PaaS credential set.',
                );
            }
        }

        return new OAuth1Credentials(
            consumerKey: $credentials['consumer_key'],
            consumerSecret: $credentials['consumer_secret'],
            accessToken: $credentials['access_token'],
            accessTokenSecret: $credentials['access_token_secret'],
        );
    }

    public static function hasCompleteSet(?array $credentials): bool
    {
        if ($credentials === null || $credentials === []) {
            return false;
        }

        try {
            self::fromStorageArray($credentials);

            return true;
        } catch (Exceptions\IncompleteAdobePaaSCredentialsException) {
            return false;
        }
    }
}
