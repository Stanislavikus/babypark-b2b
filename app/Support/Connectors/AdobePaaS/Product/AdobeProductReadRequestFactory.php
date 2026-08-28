<?php

namespace App\Support\Connectors\AdobePaaS\Product;

use App\Support\Connectors\AdobePaaS\AdobePaaSBaseUrl;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\Exceptions\InvalidAdobePaaSRequestContextException;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;

final class AdobeProductReadRequestFactory
{
    public function __construct(
        private readonly OAuth1RequestSigner $signer,
    ) {}

    public function build(
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
        string $sku,
        #[\SensitiveParameter] OAuth1SigningContext $signingContext,
    ): RequestInterface {
        if ($sku === '') {
            throw new AdobeProductReadException('Magento Product SKU must not be empty.');
        }

        $absoluteUrl = $this->buildAbsoluteUrl($context, $sku);
        $request = new Request('GET', $absoluteUrl);
        $authorizationHeader = $this->signer->sign(
            'GET',
            $absoluteUrl,
            null,
            null,
            $context->credentials,
            $signingContext,
        );

        return $request->withHeader('Authorization', $authorizationHeader);
    }

    private function buildAbsoluteUrl(
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
        string $sku,
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
        $path .= '/rest/'.rawurlencode($context->storeCode).'/V1/products/'.rawurlencode($sku);
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        return $parsed['scheme'].'://'.$parsed['host'].$port.$path;
    }
}
