<?php

namespace App\Support\Connectors\AdobePaaS\Media;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductCommandRequestFactory;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use Psr\Http\Message\RequestInterface;

final class AdobeProductMediaRemoteStateClient
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductCommandRequestFactory $requestFactory,
        private readonly ConnectorHttpTransport $transport,
        private readonly AdobeProductRemoteMediaMetadataReader $metadataReader,
        private readonly AdobeProductSourceImageValidator $contentValidator,
    ) {}

    public function readMetadataIndex(
        string $workspaceId,
        string $connectorAccountId,
        string $sku,
    ): AdobeProductRemoteMediaMetadataIndex {
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);

        return $this->readMetadataIndexWithContext($context, $sku);
    }

    public function readMetadataIndexWithContext(
        AdobePaaSRequestContext $context,
        string $sku,
    ): AdobeProductRemoteMediaMetadataIndex {
        [$httpResult, $transportException] = $this->send(
            $this->requestFactory->buildGet(
                $context,
                $sku,
                $this->newSigningContext(),
            ),
            AdobeProductSourceImageFetchLimits::MAX_REMOTE_MEDIA_GET_RESPONSE_BYTES,
        );

        if ($transportException !== null) {
            return AdobeProductRemoteMediaMetadataIndex::untrusted('product_get_transport_failed_for_media_metadata');
        }

        if ($httpResult === null || $httpResult->statusCode < 200 || $httpResult->statusCode >= 300) {
            return AdobeProductRemoteMediaMetadataIndex::untrusted('product_get_non_success_for_media_metadata');
        }

        $productPayload = $this->extractProductPayload($httpResult->body, $sku);

        if ($productPayload === null) {
            return AdobeProductRemoteMediaMetadataIndex::untrusted('product_get_untrusted_for_media_metadata');
        }

        return $this->metadataReader->read($productPayload);
    }

    public function readMediaContent(
        AdobePaaSRequestContext $context,
        string $sku,
        int $entryId,
    ): AdobeProductRemoteMediaContentEntry {
        [$httpResult, $transportException] = $this->send(
            $this->requestFactory->buildGetMediaEntry(
                $context,
                $sku,
                $entryId,
                $this->newSigningContext(),
            ),
            AdobeProductSourceImageFetchLimits::MAX_REMOTE_MEDIA_GET_RESPONSE_BYTES,
        );

        if ($transportException !== null) {
            return AdobeProductRemoteMediaContentEntry::untrusted($entryId, 'remote_media_get_transport_failed');
        }

        if ($httpResult === null || $httpResult->statusCode < 200 || $httpResult->statusCode >= 300) {
            return AdobeProductRemoteMediaContentEntry::untrusted($entryId, 'remote_media_get_non_success');
        }

        $payload = json_decode($httpResult->body, true);

        if (! is_array($payload)) {
            return AdobeProductRemoteMediaContentEntry::untrusted($entryId, 'remote_media_get_malformed_json');
        }

        $entry = $payload;

        if (isset($payload['entry']) && is_array($payload['entry'])) {
            $entry = $payload['entry'];
        }

        $responseEntryId = $entry['id'] ?? null;

        if (! is_int($responseEntryId) && ! (is_string($responseEntryId) && ctype_digit($responseEntryId))) {
            return AdobeProductRemoteMediaContentEntry::untrusted($entryId, 'remote_media_get_entry_id_mismatch');
        }

        if ((int) $responseEntryId !== $entryId) {
            return AdobeProductRemoteMediaContentEntry::untrusted($entryId, 'remote_media_get_entry_id_mismatch');
        }

        $content = $entry['content'] ?? null;

        if (! is_array($content)) {
            return AdobeProductRemoteMediaContentEntry::untrusted($entryId, 'remote_media_get_missing_content');
        }

        $base64 = $content['base64_encoded_data'] ?? null;
        $declaredType = $content['type'] ?? null;
        $name = $content['name'] ?? null;

        if (! is_string($base64) || $base64 === '') {
            return AdobeProductRemoteMediaContentEntry::untrusted($entryId, 'remote_media_get_missing_base64');
        }

        $rawBytes = base64_decode($base64, true);

        if ($rawBytes === false) {
            return AdobeProductRemoteMediaContentEntry::untrusted($entryId, 'remote_media_get_invalid_base64');
        }

        $validation = $this->contentValidator->validate(
            $rawBytes,
            declarationIndex: 0,
            role: AdobeProductMediaRole::Gallery,
            responseContentTypes: is_string($declaredType) ? [$declaredType] : [],
        );

        if (! $validation->accepted || $validation->verifiedImage === null) {
            return AdobeProductRemoteMediaContentEntry::untrusted($entryId, 'remote_media_get_invalid_image_bytes');
        }

        $filename = is_string($name) ? $name : $validation->verifiedImage->filename;

        return new AdobeProductRemoteMediaContentEntry(
            entryId: $entryId,
            contentSha256: $validation->verifiedImage->contentSha256,
            mimeType: $validation->verifiedImage->mimeType,
            filename: $filename,
            trustState: AdobeProductAppliedStateKnowledge::KnownApplied,
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    public function postMedia(
        AdobePaaSRequestContext $context,
        string $sku,
        AdobeProductMediaDesiredEntry $desired,
    ): array {
        return $this->send(
            $this->requestFactory->buildPostMediaEntry(
                $context,
                $sku,
                $desired,
                $this->newSigningContext(),
            ),
            AdobeProductSourceImageFetchLimits::MAX_REMOTE_MEDIA_GET_RESPONSE_BYTES,
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    public function putMedia(
        AdobePaaSRequestContext $context,
        string $sku,
        int $entryId,
        AdobeProductMediaDesiredEntry $desired,
        AdobeProductRemoteMediaMetadataEntry $remoteMetadata,
    ): array {
        return $this->send(
            $this->requestFactory->buildPutMediaEntry(
                $context,
                $sku,
                $entryId,
                $desired,
                $remoteMetadata,
                $this->newSigningContext(),
            ),
            AdobeProductSourceImageFetchLimits::MAX_REMOTE_MEDIA_GET_RESPONSE_BYTES,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractProductPayload(string $body, string $expectedSku): ?array
    {
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return null;
        }

        $product = $payload;

        if (isset($payload['product']) && is_array($payload['product'])) {
            $product = $payload['product'];
        }

        $sku = $product['sku'] ?? null;

        if (! is_string($sku) || $sku !== $expectedSku) {
            return null;
        }

        return $product;
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    private function send(
        RequestInterface $request,
        int $maxResponseBodyBytes,
    ): array {
        try {
            $limits = new ConnectorTransportLimits(
                connectTimeoutSeconds: AdobeProductSourceImageFetchLimits::CONNECT_TIMEOUT_SECONDS,
                totalTimeoutSeconds: AdobeProductSourceImageFetchLimits::TOTAL_TIMEOUT_SECONDS,
                maxResponseBodyBytes: $maxResponseBodyBytes,
            );

            return [$this->transport->send(new ConnectorOutboundRequest($request, $limits)), null];
        } catch (ConnectorTransportException $exception) {
            return [null, $exception];
        }
    }

    private function newSigningContext(): OAuth1SigningContext
    {
        return new OAuth1SigningContext(nonce: bin2hex(random_bytes(16)), timestamp: time());
    }
}
