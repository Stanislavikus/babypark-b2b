<?php

namespace App\Support\Connectors\OAuth1;

final class OAuth1BaseStringUriBuilder
{
    public function build(OAuth1RequestUrl $requestUrl): string
    {
        $defaultPort = $requestUrl->scheme === 'https' ? 443 : 80;
        $portSuffix = '';

        if ($requestUrl->port !== null && $requestUrl->port !== $defaultPort) {
            $portSuffix = ':'.$requestUrl->port;
        }

        return strtolower($requestUrl->scheme)
            .'://'
            .strtolower($requestUrl->host)
            .$portSuffix
            .$requestUrl->path;
    }
}
