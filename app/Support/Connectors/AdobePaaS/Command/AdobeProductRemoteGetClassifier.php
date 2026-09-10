<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorTransportException;

final class AdobeProductRemoteGetClassifier
{
    public function __construct(
        private readonly AdobeProductRemoteStateNormalizer $normalizer,
    ) {}

    public function classify(
        string $requestedSku,
        ?ConnectorHttpResult $httpResult,
        ?ConnectorTransportException $transportException = null,
    ): AdobeProductRemoteGetResult {
        if ($transportException !== null || $httpResult === null) {
            return new AdobeProductRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
        }

        if ($httpResult->statusCode === 200) {
            $payload = json_decode($httpResult->body, true);

            if (! is_array($payload)) {
                return new AdobeProductRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
            }

            $observed = $this->normalizer->normalize($payload, $requestedSku);

            if ($observed === null) {
                return new AdobeProductRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
            }

            return new AdobeProductRemoteGetResult(
                AdobeProductRemoteGetClassification::Found,
                $observed,
            );
        }

        if ($httpResult->statusCode === 404 && $this->isTrustedProductMissingEvidence($requestedSku, $httpResult->body)) {
            return new AdobeProductRemoteGetResult(AdobeProductRemoteGetClassification::TrustedKnownMissing);
        }

        return new AdobeProductRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
    }

    public function classifyParent(
        string $requestedSku,
        ?ConnectorHttpResult $httpResult,
        ?ConnectorTransportException $transportException = null,
    ): AdobeProductParentRemoteGetResult {
        if ($transportException !== null || $httpResult === null) {
            return new AdobeProductParentRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
        }

        if ($httpResult->statusCode === 200) {
            $payload = json_decode($httpResult->body, true);

            if (! is_array($payload)) {
                return new AdobeProductParentRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
            }

            $observed = $this->normalizer->normalizeParent($payload, $requestedSku);

            if ($observed === null) {
                return new AdobeProductParentRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
            }

            return new AdobeProductParentRemoteGetResult(
                AdobeProductRemoteGetClassification::Found,
                $observed,
            );
        }

        if ($httpResult->statusCode === 404 && $this->isTrustedProductMissingEvidence($requestedSku, $httpResult->body)) {
            return new AdobeProductParentRemoteGetResult(AdobeProductRemoteGetClassification::TrustedKnownMissing);
        }

        return new AdobeProductParentRemoteGetResult(AdobeProductRemoteGetClassification::UntrustedOrFailed);
    }

    private function isTrustedProductMissingEvidence(string $requestedSku, string $body): bool
    {
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return false;
        }

        $message = $payload['message'] ?? null;
        $parameters = $payload['parameters'] ?? null;

        if (! is_string($message) || $message === '') {
            return false;
        }

        if (! is_array($parameters)) {
            return false;
        }

        foreach ($parameters as $parameter) {
            if (is_string($parameter) && $parameter === $requestedSku) {
                return true;
            }
        }

        return false;
    }
}
