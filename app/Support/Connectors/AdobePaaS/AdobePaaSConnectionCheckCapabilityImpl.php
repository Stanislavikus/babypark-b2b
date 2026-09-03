<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\ConnectorConnectionCheckResult;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;

final class AdobePaaSConnectionCheckCapabilityImpl implements AdobePaaSConnectionCheckCapability
{
    public function __construct(
        private readonly AdobePaaSConnectionCheckRequestFactory $requestFactory,
        private readonly ConnectorHttpTransport $transport,
        private readonly AdobePaaSConnectionCheckResponseMapper $responseMapper,
        private readonly AdobePaaSConnectionCheckTransportMapper $transportMapper,
    ) {}

    public function checkConnection(#[\SensitiveParameter] AdobePaaSRequestContext $context): ConnectorConnectionCheckResult
    {
        $signingContext = new OAuth1SigningContext(
            bin2hex(random_bytes(16)),
            time(),
        );

        $request = $this->requestFactory->build($context, $signingContext);

        $outboundRequest = new ConnectorOutboundRequest(
            $request,
            new ConnectorTransportLimits(
                connectTimeoutSeconds: 5.0,
                totalTimeoutSeconds: 30.0,
                maxResponseBodyBytes: 256 * 1024,
            ),
        );

        try {
            $result = $this->transport->send($outboundRequest);
        } catch (ConnectorTransportException $exception) {
            return $this->transportMapper->map($exception);
        }

        $mapped = $this->responseMapper->map($result);

        if (! $mapped->succeeded) {
            return $mapped;
        }

        $catalogTotalCount = $this->probeCatalogTotalCount($context);

        if ($catalogTotalCount === null) {
            return $mapped;
        }

        return ConnectorConnectionCheckResult::success([
            'catalog_total_count' => $catalogTotalCount,
        ]);
    }

    private function probeCatalogTotalCount(#[\SensitiveParameter] AdobePaaSRequestContext $context): ?int
    {
        $signingContext = new OAuth1SigningContext(
            bin2hex(random_bytes(16)),
            time(),
        );

        $request = $this->requestFactory->buildProductsSearch(
            $context,
            $signingContext,
            ['pageSize' => 1],
        );

        $outboundRequest = new ConnectorOutboundRequest(
            $request,
            new ConnectorTransportLimits(
                connectTimeoutSeconds: 5.0,
                totalTimeoutSeconds: 30.0,
                maxResponseBodyBytes: 256 * 1024,
            ),
        );

        try {
            $result = $this->transport->send($outboundRequest);
        } catch (ConnectorTransportException) {
            return null;
        }

        if ($result->statusCode !== 200) {
            return null;
        }

        try {
            $decoded = json_decode($result->body, associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! $decoded instanceof \stdClass) {
            return null;
        }

        if (! is_array($decoded->items ?? null)) {
            return null;
        }

        if (! ($decoded->search_criteria ?? null) instanceof \stdClass) {
            return null;
        }

        if (! is_int($decoded->total_count ?? null) || $decoded->total_count < 0) {
            return null;
        }

        return $decoded->total_count;
    }
}
