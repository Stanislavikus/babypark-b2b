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

        return $this->responseMapper->map($result);
    }
}
