<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\Exceptions\InvalidAdobePaaSRequestContextException;
use App\Support\Connectors\ConnectorSchemaSourceEndpointPathValidator;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;

final class AdobePaaSDiscoveryRequestFactory
{
    public function __construct(
        private readonly OAuth1RequestSigner $signer,
        private readonly ConnectorSchemaSourceEndpointPathValidator $endpointPathValidator,
    ) {}

    public function build(
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
        string $endpointPath,
        int $currentPage,
        #[\SensitiveParameter] OAuth1SigningContext $signingContext,
    ): RequestInterface {
        if ($currentPage < 1 || $currentPage > 50) {
            throw new \InvalidArgumentException('Current page must be between 1 and 50.');
        }

        $normalizedPath = $this->endpointPathValidator->normalize($endpointPath);
        $absoluteUrl = $this->buildAbsoluteUrl($context, $normalizedPath, $currentPage);
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

    private function buildAbsoluteUrl(
        #[\SensitiveParameter] AdobePaaSRequestContext $context,
        string $endpointPath,
        int $currentPage,
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
        $urlWithoutQuery = $parsed['scheme'].'://'.$parsed['host'].$port.$path;
        $query = http_build_query(
            [
                'searchCriteria' => [
                    'pageSize' => 200,
                    'currentPage' => $currentPage,
                ],
            ],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        return $urlWithoutQuery.'?'.$query;
    }
}
