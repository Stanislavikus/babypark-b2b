<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckResponseMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckTransportMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;

final class AdobeSafeSyncHandshakeProbe implements AdobeSafeSyncHandshakeProbeCapability
{
    public function __construct(
        private readonly AdobeSafeSyncRequestFactory $requestFactory,
        private readonly ConnectorHttpTransport $transport,
        private readonly AdobeSafeSyncHandshakeParser $parser,
        private readonly AdobePaaSConnectionCheckResponseMapper $responseMapper,
        private readonly AdobePaaSConnectionCheckTransportMapper $transportMapper,
    ) {}

    public function probe(#[\SensitiveParameter] AdobePaaSRequestContext $context): AdobeSafeSyncHandshakeProbeResult
    {
        $request = $this->requestFactory->buildHandshake(
            $context,
            new OAuth1SigningContext(bin2hex(random_bytes(16)), time()),
        );

        try {
            $result = $this->transport->send(new ConnectorOutboundRequest(
                $request,
                new ConnectorTransportLimits(10.0, 30.0, AdobeSafeSyncContract::HANDSHAKE_MAX_RESPONSE_BYTES),
            ));
        } catch (ConnectorTransportException $exception) {
            return AdobeSafeSyncHandshakeProbeResult::failed($this->transportMapper->map($exception));
        }

        if ($result->statusCode !== 200) {
            return AdobeSafeSyncHandshakeProbeResult::failed(
                $this->responseMapper->map($result)->withProbeFamily('safe_sync_handshake'),
            );
        }

        try {
            return AdobeSafeSyncHandshakeProbeResult::succeeded($this->parser->parse($result->body));
        } catch (AdobeSafeSyncClientException) {
            return AdobeSafeSyncHandshakeProbeResult::failed(ConnectorConnectionCheckResult::httpFailure(
                ConnectorConnectionCheckErrorCode::AdobeUnexpectedResponse,
                200,
                probeFamily: 'safe_sync_handshake',
                responseShape: 'invalid_handshake_json',
            ));
        }
    }
}
