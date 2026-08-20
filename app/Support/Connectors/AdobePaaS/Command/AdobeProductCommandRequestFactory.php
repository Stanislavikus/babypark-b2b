<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobePaaSBaseUrl;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\Exceptions\InvalidAdobePaaSRequestContextException;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;

final class AdobeProductCommandRequestFactory
{
    private const string JSON_CONTENT_TYPE = 'application/json';

    public function __construct(
        private readonly OAuth1RequestSigner $signer,
    ) {}

    public function buildGet(
        AdobePaaSRequestContext $context,
        string $sku,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'GET',
            $context,
            '/V1/products/'.rawurlencode($sku),
            null,
            $signingContext,
        );
    }

    public function buildPost(
        AdobePaaSRequestContext $context,
        AdobeProductDesiredState $desiredState,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'POST',
            $context,
            '/V1/products',
            $this->encodeProductEnvelope($desiredState),
            $signingContext,
        );
    }

    public function buildPut(
        AdobePaaSRequestContext $context,
        AdobeProductDesiredState $desiredState,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'PUT',
            $context,
            '/V1/products/'.rawurlencode($desiredState->sku),
            $this->encodeProductEnvelope($desiredState),
            $signingContext,
        );
    }

    private function buildSignedRequest(
        string $method,
        AdobePaaSRequestContext $context,
        string $endpointPath,
        ?string $rawBody,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        $absoluteUrl = $this->buildAbsoluteUrl($context, $endpointPath);
        $headers = [];

        if ($rawBody !== null) {
            $headers['Content-Type'] = self::JSON_CONTENT_TYPE;
        }

        $request = new Request($method, $absoluteUrl, $headers, $rawBody);
        $authorizationHeader = $this->signer->sign(
            $method,
            $absoluteUrl,
            $rawBody === null ? null : self::JSON_CONTENT_TYPE,
            $rawBody,
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

        return $parsed['scheme'].'://'.$parsed['host'].$port.$path;
    }

    private function encodeProductEnvelope(AdobeProductDesiredState $desiredState): string
    {
        $product = [
            'sku' => $desiredState->sku,
            'name' => $desiredState->name,
            'attribute_set_id' => $desiredState->attributeSetId,
            'type_id' => $desiredState->typeId,
            'status' => $desiredState->status,
            'visibility' => $desiredState->visibility,
            'price' => $desiredState->price,
            'custom_attributes' => $this->encodeCustomAttributes($desiredState->customAttributes),
        ];

        return json_encode(['product' => $product], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $customAttributes
     * @return list<array{attribute_code: string, value: mixed}>
     */
    private function encodeCustomAttributes(array $customAttributes): array
    {
        $encoded = [];

        foreach ($customAttributes as $attributeCode => $value) {
            $encoded[] = [
                'attribute_code' => (string) $attributeCode,
                'value' => $value,
            ];
        }

        return $encoded;
    }
}
