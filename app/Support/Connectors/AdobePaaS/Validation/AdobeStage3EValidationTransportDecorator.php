<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;

final class AdobeStage3EValidationTransportDecorator implements ConnectorHttpTransport
{
    private ?AdobeStage3EValidationTransportArm $armedTransportLoss = null;

    private int $armedTransportLossFireCount = 0;

    private int $simpleProductWriteCount = 0;

    public function __construct(
        private readonly ConnectorHttpTransport $delegate,
        private readonly AdobeStage3EValidationEvidenceWriter $evidenceWriter,
    ) {}

    public function armTransportLossAfterWrite(AdobeStage3EValidationTransportArm $arm): void
    {
        $this->armedTransportLoss = $arm;
    }

    public function armedTransportLossFireCount(): int
    {
        return $this->armedTransportLossFireCount;
    }

    public function simpleProductWriteCount(): int
    {
        return $this->simpleProductWriteCount;
    }

    public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
    {
        $metadata = $this->describeRequest($request);

        if ($metadata !== null) {
            if ($metadata['resource_family'] === 'entity_bound_simple_product_write') {
                $this->simpleProductWriteCount++;
            }
        }

        $result = $this->delegate->send($request);

        if ($metadata !== null) {
            $this->evidenceWriter->recordHttpEvent([
                'timestamp' => now()->toIso8601String(),
                'method' => $metadata['method'],
                'resource_family' => $metadata['resource_family'],
                'normalized_host' => $metadata['normalized_host'],
                'store_code' => $metadata['store_code'],
                'logical_entity_id' => $metadata['logical_entity_id'],
                'status_code' => $result->statusCode,
                'response_body_sha256' => hash('sha256', $result->body),
            ]);
        }

        if ($metadata !== null && $this->matchesArmedTransportLoss($metadata)) {
            $this->armedTransportLoss = null;
            $this->armedTransportLossFireCount++;

            throw new ConnectorTransportException(TransportFailureReason::OtherTransportFailure);
        }

        return $result;
    }

    /**
     * @return array{
     *   method:string,
     *   normalized_host:string,
     *   store_code:string,
     *   resource_family:string,
     *   logical_entity_id:?int
     * }|null
     */
    private function describeRequest(ConnectorOutboundRequest $request): ?array
    {
        $uri = $request->request->getUri();
        $path = $uri->getPath();
        $host = strtolower($uri->getHost());
        $method = strtoupper($request->request->getMethod());

        if (preg_match('#/rest/([^/]+)/V1/safe-sync/handshake$#', $path, $matches) === 1) {
            return [
                'method' => $method,
                'normalized_host' => $host,
                'store_code' => rawurldecode($matches[1]),
                'resource_family' => 'safe_sync_handshake',
                'logical_entity_id' => null,
            ];
        }

        if (preg_match('#/rest/([^/]+)/V1/safe-sync/products/([1-9][0-9]*)$#', $path, $matches) === 1) {
            return [
                'method' => $method,
                'normalized_host' => $host,
                'store_code' => rawurldecode($matches[1]),
                'resource_family' => $method === 'PUT'
                    ? 'entity_bound_simple_product_write'
                    : 'entity_bound_product_read',
                'logical_entity_id' => (int) $matches[2],
            ];
        }

        return null;
    }

    /**
     * @param  array{
     *   method:string,
     *   normalized_host:string,
     *   store_code:string,
     *   resource_family:string,
     *   logical_entity_id:?int
     * }  $metadata
     */
    private function matchesArmedTransportLoss(array $metadata): bool
    {
        if (! $this->armedTransportLoss instanceof AdobeStage3EValidationTransportArm) {
            return false;
        }

        return $metadata['method'] === 'PUT'
            && $metadata['resource_family'] === 'entity_bound_simple_product_write'
            && $metadata['normalized_host'] === $this->armedTransportLoss->normalizedHost
            && $metadata['store_code'] === $this->armedTransportLoss->storeCode
            && $metadata['logical_entity_id'] === $this->armedTransportLoss->logicalEntityId;
    }
}
