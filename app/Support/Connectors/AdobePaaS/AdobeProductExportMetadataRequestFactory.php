<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\Exceptions\InvalidAdobePaaSRequestContextException;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;

final class AdobeProductExportMetadataRequestFactory
{
    public function __construct(
        private readonly OAuth1RequestSigner $signer,
    ) {}

    public function build(
        AdobePaaSRequestContext $context,
        string $endpointPath,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        $absoluteUrl = $this->buildAbsoluteUrl($context, $endpointPath);
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

    private function buildAbsoluteUrl(AdobePaaSRequestContext $context, string $endpointPath): string
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

        if (! $this->requiresSearchCriteria($endpointPath)) {
            return $urlWithoutQuery;
        }

        $query = http_build_query(
            ['searchCriteria' => ['pageSize' => 200]],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        return $urlWithoutQuery.'?'.$query;
    }

    private function requiresSearchCriteria(string $endpointPath): bool
    {
        return str_ends_with($endpointPath, '/list');
    }
}
