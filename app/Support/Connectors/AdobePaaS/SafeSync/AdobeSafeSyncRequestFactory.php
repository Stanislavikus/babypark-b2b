<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

use App\Support\Connectors\AdobePaaS\AdobePaaSBaseUrl;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\Exceptions\InvalidAdobePaaSRequestContextException;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;

final class AdobeSafeSyncRequestFactory
{
    public function __construct(
        private readonly OAuth1RequestSigner $signer,
    ) {}

    public function buildHandshake(
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
        #[\SensitiveParameter] OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'GET',
            $context,
            '/V1/safe-sync/handshake',
            [],
            $signingContext,
        );
    }

    public function buildReadProduct(
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
        int $logicalEntityId,
        string $expectedSku,
        #[\SensitiveParameter] OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'GET',
            $context,
            '/V1/safe-sync/products/'.rawurlencode((string) $logicalEntityId),
            ['expectedSku' => $expectedSku],
            $signingContext,
        );
    }

    /**
     * @param  array<string, string>  $query
     */
    private function buildSignedRequest(
        string $method,
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
        string $endpointPath,
        array $query,
        #[\SensitiveParameter] OAuth1SigningContext $signingContext,
    ): RequestInterface {
        $absoluteUrl = $this->buildAbsoluteUrl($context, $endpointPath, $query);
        $request = new Request($method, $absoluteUrl);
        $authorizationHeader = $this->signer->sign(
            $method,
            $absoluteUrl,
            null,
            null,
            $context->credentials,
            $signingContext,
        );

        return $request->withHeader('Authorization', $authorizationHeader);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function buildAbsoluteUrl(
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
        string $endpointPath,
        array $query,
    ): string {
        if ($context->storeCode === '') {
            throw new InvalidAdobePaaSRequestContextException('Adobe PaaS store code must not be empty.');
        }

        $baseUrl = AdobePaaSBaseUrl::parse($context->baseUrl);
        $parsed = parse_url($baseUrl->value);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            throw new InvalidAdobePaaSRequestContextException('Adobe PaaS base URL must be an absolute URL.');
        }

        $path = rtrim($parsed['path'] ?? '', '/');
        $path .= '/rest/'.rawurlencode($context->storeCode).$endpointPath;

        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $url = $parsed['scheme'].'://'.$parsed['host'].$port.$path;

        if ($query === []) {
            return $url;
        }

        return $url.'?'.http_build_query($query, encoding_type: PHP_QUERY_RFC3986);
    }
}
