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
        $absoluteUrl = $this->buildAbsoluteUrl($context, '/V1/products/attributes', ['pageSize' => 1]);
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

    /**
     * @param  array<string, mixed>  $searchCriteria
     */
    public function buildProductsSearch(
        AdobePaaSRequestContext $context,
        OAuth1SigningContext $signingContext,
        array $searchCriteria,
    ): RequestInterface {
        $absoluteUrl = $this->buildAbsoluteUrl($context, '/V1/products', $searchCriteria);
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

    public function buildProductMedia(
        AdobePaaSRequestContext $context,
        OAuth1SigningContext $signingContext,
        string $sku,
    ): RequestInterface {
        $absoluteUrl = $this->buildAbsoluteEndpointUrl(
            $context,
            '/V1/products/'.rawurlencode($sku).'/media',
        );
        $request = new Request('GET', $absoluteUrl);

        return $request->withHeader('Authorization', $this->signer->sign(
            $request->getMethod(),
            (string) $request->getUri(),
            null,
            null,
            $context->credentials,
            $signingContext,
        ));
    }

    /**
     * @param  array<string, mixed>  $searchCriteria
     */
    private function buildAbsoluteUrl(
        AdobePaaSRequestContext $context,
        string $endpointPath,
        array $searchCriteria,
    ): string {
        if ($context->storeCode === '') {
            throw new InvalidAdobePaaSRequestContextException('Adobe PaaS store code must not be empty.');
        }

        $urlWithoutQuery = $this->buildAbsoluteEndpointUrl($context, $endpointPath);
        $query = http_build_query(
            ['searchCriteria' => $searchCriteria],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        return $urlWithoutQuery.'?'.$query;
    }

    private function buildAbsoluteEndpointUrl(AdobePaaSRequestContext $context, string $endpointPath): string
    {
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
        $urlWithoutQuery = $parsed['scheme'].'://'.$parsed['host'].$port.$path;

        return $urlWithoutQuery;
    }
}
