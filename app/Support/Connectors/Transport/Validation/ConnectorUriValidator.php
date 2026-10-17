<?php

namespace App\Support\Connectors\Transport\Validation;

use Psr\Http\Message\UriInterface;

final class ConnectorUriValidator
{
    public const DEFAULT_HTTP_PORT = 80;

    public const DEFAULT_HTTPS_PORT = 443;

    /**
     * @return array{scheme: string, host: string, port: int, isIpLiteral: bool, normalizedIp: ?string}
     */
    public static function validate(#[\SensitiveParameter] UriInterface $uri): array
    {
        if ($uri->getUserInfo() !== '') {
            throw new \InvalidArgumentException('userinfo');
        }

        if ($uri->getFragment() !== '') {
            throw new \InvalidArgumentException('fragment');
        }

        if (! $uri->getHost()) {
            throw new \InvalidArgumentException('host');
        }

        if ($uri->getHost() === '' || str_starts_with((string) $uri->getPath(), '//')) {
            throw new \InvalidArgumentException('relative');
        }

        $scheme = strtolower($uri->getScheme());
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('scheme');
        }

        $host = $uri->getHost();
        $port = $uri->getPort();
        if ($port === null) {
            $port = $scheme === 'https' ? self::DEFAULT_HTTPS_PORT : self::DEFAULT_HTTP_PORT;
        }

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('port');
        }

        $normalizedIp = IpLiteralGrammar::tryParse($host);
        if ($normalizedIp !== null) {
            return [
                'scheme' => $scheme,
                'host' => $host,
                'port' => $port,
                'isIpLiteral' => true,
                'normalizedIp' => $normalizedIp,
            ];
        }

        $normalizedHost = HostnameGrammar::normalize($host);
        if (! HostnameGrammar::isValid($normalizedHost)) {
            throw new \InvalidArgumentException('hostname');
        }

        return [
            'scheme' => $scheme,
            'host' => $normalizedHost,
            'port' => $port,
            'isIpLiteral' => false,
            'normalizedIp' => null,
        ];
    }
}
