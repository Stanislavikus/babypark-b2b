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
        [$httpResult, $transportException] = $this->sendProductMetadataGet(
            $context,
            $sku,
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

    public function readMediaEntrySnapshot(
        AdobePaaSRequestContext $context,
        string $sku,
        int $entryId,
    ): AdobeProductRemoteMediaEntrySnapshot {
        [$httpResult, $transportException] = $this->sendIndividualMediaGet(
            $context,
            $sku,
            $entryId,
        );

        if ($transportException !== null) {
            return AdobeProductRemoteMediaEntrySnapshot::untrusted($entryId, 'remote_media_get_transport_failed');
        }

        if ($httpResult === null || $httpResult->statusCode < 200 || $httpResult->statusCode >= 300) {
            return AdobeProductRemoteMediaEntrySnapshot::untrusted($entryId, 'remote_media_get_non_success');
        }

        return $this->parseMediaEntrySnapshot($httpResult->body, $entryId);
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    public function postMedia(
        AdobePaaSRequestContext $context,
        string $sku,
        AdobeProductMediaDesiredEntry $desired,
    ): array {
        return $this->sendMutation(
            $this->requestFactory->buildPostMediaEntry(
                $context,
                $sku,
                $desired,
                $this->newSigningContext(),
            ),
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
        return $this->sendMutation(
            $this->requestFactory->buildPutMediaEntry(
                $context,
                $sku,
                $entryId,
                $desired,
                $remoteMetadata,
                $this->newSigningContext(),
            ),
        );
    }

    public function buildReconciliationIndex(
        AdobePaaSRequestContext $context,
        string $sku,
        AdobeProductRemoteMediaMetadataIndex $metadataIndex,
    ): ?AdobeProductRemoteMediaReconciliationIndex {
        $entriesByContentHash = [];
        $imageFilenameIndex = [];

        foreach ($metadataIndex->entries as $metadata) {
            $snapshot = $this->readMediaEntrySnapshot($context, $sku, $metadata->entryId);

            if (! $snapshot->isTrusted()) {
                return null;
            }

            $entriesByContentHash[$snapshot->contentSha256] ??= [];
            $entriesByContentHash[$snapshot->contentSha256][] = $snapshot->metadata;

            $basename = basename($metadata->file);

            if ($basename !== '') {
                $imageFilenameIndex[$basename] = [
                    'entryId' => $metadata->entryId,
                    'contentSha256' => $snapshot->contentSha256,
                ];
            }
        }

        return new AdobeProductRemoteMediaReconciliationIndex(
            entriesByContentHash: $entriesByContentHash,
            imageFilenameIndex: $imageFilenameIndex,
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    private function sendProductMetadataGet(
        AdobePaaSRequestContext $context,
        string $sku,
    ): array {
        return $this->send(
            $this->requestFactory->buildGet(
                $context,
                $sku,
                $this->newSigningContext(),
            ),
            AdobeProductMediaApiLimits::MAX_PRODUCT_METADATA_RESPONSE_BYTES,
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    private function sendIndividualMediaGet(
        AdobePaaSRequestContext $context,
        string $sku,
        int $entryId,
    ): array {
        return $this->send(
            $this->requestFactory->buildGetMediaEntry(
                $context,
                $sku,
                $entryId,
                $this->newSigningContext(),
            ),
            AdobeProductMediaApiLimits::MAX_INDIVIDUAL_MEDIA_GET_RESPONSE_BYTES,
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    private function sendMutation(RequestInterface $request): array
    {
        return $this->send($request, AdobeProductMediaApiLimits::MAX_MUTATION_RESPONSE_BYTES);
    }

    private function parseMediaEntrySnapshot(string $body, int $requestedEntryId): AdobeProductRemoteMediaEntrySnapshot
    {
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return AdobeProductRemoteMediaEntrySnapshot::untrusted($requestedEntryId, 'remote_media_get_malformed_json');
        }

        $entry = $payload;

        if (isset($payload['entry']) && is_array($payload['entry'])) {
            $entry = $payload['entry'];
        }

        $responseEntryId = $entry['id'] ?? null;

        if (! is_int($responseEntryId) && ! (is_string($responseEntryId) && ctype_digit($responseEntryId))) {
            return AdobeProductRemoteMediaEntrySnapshot::untrusted($requestedEntryId, 'remote_media_get_entry_id_mismatch');
        }

        if ((int) $responseEntryId !== $requestedEntryId) {
            return AdobeProductRemoteMediaEntrySnapshot::untrusted($requestedEntryId, 'remote_media_get_entry_id_mismatch');
        }

        $metadata = $this->metadataReader->parseImageEntryFromIndividualGet($entry);

        if ($metadata === null) {
            return AdobeProductRemoteMediaEntrySnapshot::untrusted($requestedEntryId, 'remote_media_get_malformed_metadata');
        }

        $content = $entry['content'] ?? null;

        if (! is_array($content)) {
            return AdobeProductRemoteMediaEntrySnapshot::untrusted($requestedEntryId, 'remote_media_get_missing_content');
        }

        $base64 = $content['base64_encoded_data'] ?? null;
        $declaredType = $content['type'] ?? null;
        $name = $content['name'] ?? null;

        if (! is_string($base64) || $base64 === '') {
            return AdobeProductRemoteMediaEntrySnapshot::untrusted($requestedEntryId, 'remote_media_get_missing_base64');
        }

        $rawBytes = base64_decode($base64, true);

        if ($rawBytes === false) {
            return AdobeProductRemoteMediaEntrySnapshot::untrusted($requestedEntryId, 'remote_media_get_invalid_base64');
        }

        $validation = $this->contentValidator->validate(
            $rawBytes,
            declarationIndex: 0,
            role: AdobeProductMediaRole::Gallery,
            responseContentTypes: is_string($declaredType) ? [$declaredType] : [],
        );

        if (! $validation->accepted || $validation->verifiedImage === null) {
            return AdobeProductRemoteMediaEntrySnapshot::untrusted($requestedEntryId, 'remote_media_get_invalid_image_bytes');
        }

        $filename = is_string($name) && $name !== '' ? $name : $validation->verifiedImage->filename;

        return new AdobeProductRemoteMediaEntrySnapshot(
            entryId: $requestedEntryId,
            metadata: $metadata,
            contentSha256: $validation->verifiedImage->contentSha256,
            mimeType: $validation->verifiedImage->mimeType,
            filename: $filename,
            trustState: AdobeProductAppliedStateKnowledge::KnownApplied,
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
                connectTimeoutSeconds: AdobeProductMediaApiLimits::CONNECT_TIMEOUT_SECONDS,
                totalTimeoutSeconds: AdobeProductMediaApiLimits::TOTAL_TIMEOUT_SECONDS,
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
