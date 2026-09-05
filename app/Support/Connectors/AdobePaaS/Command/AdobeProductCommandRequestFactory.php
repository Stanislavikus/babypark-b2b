<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobePaaSBaseUrl;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\Exceptions\InvalidAdobePaaSRequestContextException;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductMediaDesiredEntry;
use App\Support\Connectors\AdobePaaS\Media\AdobeProductRemoteMediaMetadataEntry;
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

    public function buildPostParent(
        AdobePaaSRequestContext $context,
        AdobeProductParentDesiredState $desiredState,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'POST',
            $context,
            '/V1/products',
            $this->encodeParentProductEnvelope($desiredState),
            $signingContext,
        );
    }

    public function buildPutParent(
        AdobePaaSRequestContext $context,
        AdobeProductParentDesiredState $desiredState,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'PUT',
            $context,
            '/V1/products/'.rawurlencode($desiredState->sku),
            $this->encodeParentProductEnvelope($desiredState),
            $signingContext,
        );
    }

    public function buildPutProductStatus(
        AdobePaaSRequestContext $context,
        string $sku,
        int $status,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        $payload = json_encode([
            'product' => [
                'sku' => $sku,
                'status' => $status,
            ],
        ], JSON_THROW_ON_ERROR);

        return $this->buildSignedRequest(
            'PUT',
            $context,
            '/V1/products/'.rawurlencode($sku),
            $payload,
            $signingContext,
        );
    }

    public function buildGetConfigurableOptions(
        AdobePaaSRequestContext $context,
        string $parentSku,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'GET',
            $context,
            '/V1/configurable-products/'.rawurlencode($parentSku).'/options/all',
            null,
            $signingContext,
        );
    }

    public function buildPostConfigurableOption(
        AdobePaaSRequestContext $context,
        string $parentSku,
        AdobeConfigurableOptionDesiredState $desiredOption,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'POST',
            $context,
            '/V1/configurable-products/'.rawurlencode($parentSku).'/options',
            $this->encodeConfigurableOptionPayload($desiredOption),
            $signingContext,
        );
    }

    public function buildPutConfigurableOption(
        AdobePaaSRequestContext $context,
        string $parentSku,
        int $optionId,
        AdobeConfigurableOptionDesiredState $desiredOption,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'PUT',
            $context,
            '/V1/configurable-products/'.rawurlencode($parentSku).'/options/'.$optionId,
            $this->encodeConfigurableOptionPayload($desiredOption),
            $signingContext,
        );
    }

    public function buildGetConfigurableChildren(
        AdobePaaSRequestContext $context,
        string $parentSku,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'GET',
            $context,
            '/V1/configurable-products/'.rawurlencode($parentSku).'/children',
            null,
            $signingContext,
        );
    }

    public function buildPostConfigurableChildLink(
        AdobePaaSRequestContext $context,
        string $parentSku,
        string $childSku,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        $payload = json_encode(['childSku' => $childSku], JSON_THROW_ON_ERROR);

        return $this->buildSignedRequest(
            'POST',
            $context,
            '/V1/configurable-products/'.rawurlencode($parentSku).'/child',
            $payload,
            $signingContext,
        );
    }

    public function buildGetMediaEntry(
        AdobePaaSRequestContext $context,
        string $sku,
        int $entryId,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'GET',
            $context,
            '/V1/products/'.rawurlencode($sku).'/media/'.$entryId,
            null,
            $signingContext,
        );
    }

    public function buildPostMediaEntry(
        AdobePaaSRequestContext $context,
        string $sku,
        AdobeProductMediaDesiredEntry $desired,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'POST',
            $context,
            '/V1/products/'.rawurlencode($sku).'/media',
            $this->encodeMediaEntryPayload(null, $desired, includeContent: true),
            $signingContext,
        );
    }

    public function buildPutMediaEntry(
        AdobePaaSRequestContext $context,
        string $sku,
        int $entryId,
        AdobeProductMediaDesiredEntry $desired,
        AdobeProductRemoteMediaMetadataEntry $remoteMetadata,
        OAuth1SigningContext $signingContext,
    ): RequestInterface {
        return $this->buildSignedRequest(
            'PUT',
            $context,
            '/V1/products/'.rawurlencode($sku).'/media/'.$entryId,
            $this->encodeMediaEntryPayload($entryId, $desired, includeContent: false, remoteMetadata: $remoteMetadata),
            $signingContext,
        );
    }

    private function encodeMediaEntryPayload(
        ?int $entryId,
        AdobeProductMediaDesiredEntry $desired,
        bool $includeContent,
        ?AdobeProductRemoteMediaMetadataEntry $remoteMetadata = null,
    ): string {
        $entry = [
            'id' => $entryId,
            'media_type' => 'image',
            'label' => $desired->label,
            'position' => $desired->position,
            'types' => $desired->magentoTypes(),
            'disabled' => false,
        ];

        if ($includeContent) {
            $entry['content'] = [
                'base64_encoded_data' => base64_encode($desired->rawBytes),
                'type' => $desired->mimeType,
                'name' => $desired->filename,
            ];
        } elseif ($remoteMetadata !== null) {
            $entry['file'] = $remoteMetadata->file;
        }

        return json_encode(['entry' => $entry], JSON_THROW_ON_ERROR);
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

    private function encodeParentProductEnvelope(AdobeProductParentDesiredState $desiredState): string
    {
        $product = [
            'sku' => $desiredState->sku,
            'name' => $desiredState->name,
            'attribute_set_id' => $desiredState->attributeSetId,
            'type_id' => $desiredState->typeId,
            'status' => $desiredState->status,
            'visibility' => $desiredState->visibility,
            'custom_attributes' => $this->encodeCustomAttributes($desiredState->customAttributes),
        ];

        return json_encode(['product' => $product], JSON_THROW_ON_ERROR);
    }

    private function encodeConfigurableOptionPayload(AdobeConfigurableOptionDesiredState $desiredOption): string
    {
        $values = [];

        foreach ($desiredOption->values as $value) {
            $values[] = [
                'value_index' => $value->valueIndex,
            ];
        }

        $option = [
            'attribute_id' => (string) $desiredOption->attributeId,
            'label' => $desiredOption->label,
            'position' => $desiredOption->position,
            'is_use_default' => true,
            'values' => $values,
        ];

        return json_encode(['option' => $option], JSON_THROW_ON_ERROR);
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
