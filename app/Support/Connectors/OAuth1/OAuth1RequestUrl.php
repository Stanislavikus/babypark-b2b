<?php

namespace App\Support\Connectors\OAuth1;

use App\Support\Connectors\OAuth1\Exceptions\OAuth1StructuralException;

final readonly class OAuth1RequestUrl
{
    public function __construct(
        public string $scheme,
        public string $host,
        public ?int $port,
        public string $path,
        public string $rawQuery,
    ) {}

    public static function parse(string $absoluteUrl): self
    {
        if ($absoluteUrl === '' || ! preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $absoluteUrl)) {
            throw new OAuth1StructuralException('Request URL must be an absolute URL with a scheme.');
        }

        $withoutFragment = preg_replace('/#.*$/', '', $absoluteUrl) ?? $absoluteUrl;
        $parsed = parse_url($withoutFragment);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            throw new OAuth1StructuralException('Request URL must include a supported scheme and host.');
        }

        $scheme = strtolower($parsed['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new OAuth1StructuralException('Request URL scheme is not supported.');
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new OAuth1StructuralException('Request URL must not include userinfo.');
        }

        $port = $parsed['port'] ?? null;

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new OAuth1StructuralException('Request URL port is out of range.');
        }

        $schemeMarker = '://';
        $schemeEnd = strpos($withoutFragment, $schemeMarker);

        if ($schemeEnd === false) {
            throw new OAuth1StructuralException('Request URL must include a supported scheme and host.');
        }

        $authorityStart = $schemeEnd + strlen($schemeMarker);
        $pathStart = strpos($withoutFragment, '/', $authorityStart);

        if ($pathStart === false) {
            $authority = substr($withoutFragment, $authorityStart);
            $path = '/';
            $rawQuery = '';
        } else {
            $authority = substr($withoutFragment, $authorityStart, $pathStart - $authorityStart);
            $pathAndQuery = substr($withoutFragment, $pathStart);
            $queryStart = strpos($pathAndQuery, '?');

            if ($queryStart === false) {
                $path = $pathAndQuery === '' ? '/' : $pathAndQuery;
                $rawQuery = '';
            } else {
                $path = substr($pathAndQuery, 0, $queryStart);
                $path = $path === '' ? '/' : $path;
                $rawQuery = substr($pathAndQuery, $queryStart + 1);
            }
        }

        if (str_contains($authority, '@')) {
            throw new OAuth1StructuralException('Request URL must not include userinfo.');
        }

        return new self(
            scheme: $scheme,
            host: $parsed['host'],
            port: $port,
            path: $path,
            rawQuery: $rawQuery,
        );
    }
}
