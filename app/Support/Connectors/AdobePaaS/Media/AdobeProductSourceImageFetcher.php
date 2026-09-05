<?php

namespace App\Support\Connectors\AdobePaaS\Media;

use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use GuzzleHttp\Psr7\Request;

final class AdobeProductSourceImageFetcher
{
    public function __construct(
        private readonly ConnectorHttpTransport $transport,
        private readonly AdobeProductSourceImageValidator $validator,
    ) {}

    public function fetchAndValidate(
        string $sourceReference,
        int $declarationIndex,
        AdobeProductMediaRole $role,
    ): AdobeProductSourceImageValidationResult {
        try {
            $request = new Request('GET', $sourceReference);
        } catch (\InvalidArgumentException) {
            return AdobeProductSourceImageValidationResult::rejected('source_reference_invalid');
        }

        try {
            $limits = new ConnectorTransportLimits(
                connectTimeoutSeconds: AdobeProductSourceImageFetchLimits::CONNECT_TIMEOUT_SECONDS,
                totalTimeoutSeconds: AdobeProductSourceImageFetchLimits::TOTAL_TIMEOUT_SECONDS,
                maxResponseBodyBytes: AdobeProductSourceImageFetchLimits::MAX_SOURCE_RESPONSE_BYTES,
            );

            $result = $this->transport->send(new ConnectorOutboundRequest($request, $limits));
        } catch (ConnectorTransportException) {
            return AdobeProductSourceImageValidationResult::rejected('source_fetch_transport_failed');
        }

        if ($result->statusCode >= 300 && $result->statusCode < 400) {
            return AdobeProductSourceImageValidationResult::rejected('source_redirect_rejected');
        }

        if ($result->statusCode < 200 || $result->statusCode >= 300) {
            return AdobeProductSourceImageValidationResult::rejected('source_fetch_non_success');
        }

        $contentTypes = $result->headers['Content-Type'] ?? [];

        return $this->validator->validate(
            $result->body,
            $declarationIndex,
            $role,
            $contentTypes,
        );
    }
}
