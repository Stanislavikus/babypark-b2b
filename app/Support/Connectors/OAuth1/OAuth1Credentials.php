<?php

namespace App\Support\Connectors\OAuth1;

final readonly class OAuth1Credentials
{
    public function __construct(
        public string $consumerKey,
        public string $consumerSecret,
        public string $accessToken,
        public string $accessTokenSecret,
    ) {}
}
