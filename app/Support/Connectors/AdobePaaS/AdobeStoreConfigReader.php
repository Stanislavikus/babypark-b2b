<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\Exceptions\AdobeStoreConfigReadException;
use App\Support\Connectors\AdobePaaS\Exceptions\InvalidAdobePaaSRequestContextException;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;

final class AdobeStoreConfigReader
{
    private const string STORE_CONFIGS_ENDPOINT = '/V1/store/storeConfigs';

    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly OAuth1RequestSigner $signer,
        private readonly ConnectorHttpTransport $transport,
    ) {}

    public function readBaseCurrency(string $workspaceId, string $connectorAccountId): string
    {
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);
        $request = $this->buildGetRequest($context, $context->storeCode);

        $outboundRequest = new ConnectorOutboundRequest(
            $request,
            new ConnectorTransportLimits(
                connectTimeoutSeconds: 10.0,
                totalTimeoutSeconds: 60.0,
                maxResponseBodyBytes: 2 * 1024 * 1024,
            ),
        );

        try {
            $httpResult = $this->transport->send($outboundRequest);
        } catch (ConnectorTransportException) {
            throw AdobeStoreConfigReadException::transportFailure();
        }

        if ($httpResult->statusCode < 200 || $httpResult->statusCode >= 300) {
            throw AdobeStoreConfigReadException::transportFailure();
        }

        $payload = json_decode($httpResult->body, true);

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw AdobeStoreConfigReadException::invalidResponse();
        }

        if (count($payload) !== 1) {
            throw AdobeStoreConfigReadException::unexpectedShape();
        }

        $storeRow = $payload[0];

        if (! is_array($storeRow)) {
            throw AdobeStoreConfigReadException::unexpectedShape();
        }

        $code = $storeRow['code'] ?? null;

        if (! is_string($code) || $code !== $context->storeCode) {
            throw AdobeStoreConfigReadException::unexpectedShape();
        }

        $baseCurrency = $storeRow['base_currency_code'] ?? null;

        if (! is_string($baseCurrency) || $baseCurrency === '') {
            throw AdobeStoreConfigReadException::missingBaseCurrency();
        }

        return $baseCurrency;
    }

    private function buildGetRequest(AdobePaaSRequestContext $context, string $storeCode): RequestInterface
    {
        $absoluteUrl = $this->buildAbsoluteUrl($context, self::STORE_CONFIGS_ENDPOINT, $storeCode);
        $signingContext = new OAuth1SigningContext(
            bin2hex(random_bytes(16)),
            time(),
        );
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
        AdobePaaSRequestContext $context,
        string $endpointPath,
        string $storeCodeFilter,
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
        $query = 'storeCodes[]='.rawurlencode($storeCodeFilter);

        return $urlWithoutQuery.'?'.$query;
    }
}
