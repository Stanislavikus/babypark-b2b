<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\OAuth1\OAuth1Credentials;

final readonly class AdobePaaSRequestContext
{
    public function __construct(
        public string $baseUrl,
        public string $storeCode,
        public OAuth1Credentials $credentials,
    ) {}
}
