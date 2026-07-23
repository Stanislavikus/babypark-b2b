<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\Exceptions\InvalidAdobePaaSRequestContextException;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;

final class AdobePaaSConnectionCheckRequestFactory
{
    public function __construct(
        private readonly OAuth1RequestSigner $signer,
    ) {}

    public function build(
        AdobePaaSRequestContext $context,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        $absoluteUrl = $this->buildAbsoluteUrl($context);
        $request = new Request('GET', $absoluteUrl);

        $authorizationHeader = $this->signer->sign(
            $request->getMethod(),
            (string) $request->getUri(),
            null,
            null,
            $context->credentials,
            $signingContext,
        );

        return $request->withHeader('Authorization', $authorizationHeader);
    }

    private function buildAbsoluteUrl(AdobePaaSRequestContext $context): string
    {
        if ($context->storeCode === '') {
            throw new InvalidAdobePaaSRequestContextException('Adobe PaaS store code must not be empty.');
        }

        $parsed = parse_url($context->baseUrl);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            throw new InvalidAdobePaaSRequestContextException('Adobe PaaS base URL must be an absolute URL.');
        }

        if (isset($parsed['query']) || isset($parsed['fragment'])) {
            throw new InvalidAdobePaaSRequestContextException(
                'Adobe PaaS base URL must not contain a query string or fragment.',
            );
        }

        $path = rtrim($parsed['path'] ?? '', '/');
        $path .= '/rest/'.rawurlencode($context->storeCode).'/V1/products/attributes';

        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $urlWithoutQuery = $parsed['scheme'].'://'.$parsed['host'].$port.$path;
        $query = http_build_query(
            ['searchCriteria' => ['pageSize' => 1]],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        return $urlWithoutQuery.'?'.$query;
    }
}
