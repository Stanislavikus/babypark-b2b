<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\RetryAfterHeaderNormalizer;
use App\Support\Connectors\Transport\ConnectorHttpResult;

final class AdobePaaSDiscoveryResponseMapper
{
    public function map(#[\SensitiveParameter] ConnectorHttpResult $result): AdobePaaSDiscoveryPageResult
    {
        if ($result->statusCode === 200) {
            return $this->mapSuccessResponse($result->body);
        }

        return AdobePaaSDiscoveryPageResult::failure(
            $this->mapHttpStatus($result->statusCode, $result->headers),
        );
    }

    private function mapSuccessResponse(#[\SensitiveParameter] string $body): AdobePaaSDiscoveryPageResult
    {
        try {
            $decoded = json_decode($body, associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return AdobePaaSDiscoveryPageResult::failure(
                ConnectorDiscoveryAttemptResult::httpFailure(
                    ConnectorDiscoveryRunErrorCode::AdobeUnexpectedResponse,
                    200,
                ),
            );
        }

        if (! $decoded instanceof \stdClass) {
            return AdobePaaSDiscoveryPageResult::failure(
                ConnectorDiscoveryAttemptResult::httpFailure(
                    ConnectorDiscoveryRunErrorCode::AdobeUnexpectedResponse,
                    200,
                ),
            );
        }

        if (! is_array($decoded->items ?? null) || ! array_is_list($decoded->items)) {
            return AdobePaaSDiscoveryPageResult::failure(
                ConnectorDiscoveryAttemptResult::httpFailure(
                    ConnectorDiscoveryRunErrorCode::AdobeUnexpectedResponse,
                    200,
                ),
            );
        }

        if (! is_int($decoded->total_count ?? null) || $decoded->total_count < 0) {
            return AdobePaaSDiscoveryPageResult::failure(
                ConnectorDiscoveryAttemptResult::httpFailure(
                    ConnectorDiscoveryRunErrorCode::AdobeUnexpectedResponse,
                    200,
                ),
            );
        }

        return AdobePaaSDiscoveryPageResult::success(
            new AdobePaaSDiscoveryPage($decoded->items, $decoded->total_count),
        );
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function mapHttpStatus(int $status, #[\SensitiveParameter] array $headers): ConnectorDiscoveryAttemptResult
    {
        $errorCode = match (true) {
            $status >= 201 && $status <= 299 => ConnectorDiscoveryRunErrorCode::AdobeUnexpectedSuccessStatus,
            $status >= 300 && $status <= 399 => ConnectorDiscoveryRunErrorCode::AdobeRedirectResponse,
            $status === 400 => ConnectorDiscoveryRunErrorCode::AdobeUnrecognizedBadRequest,
            $status === 401 => ConnectorDiscoveryRunErrorCode::AdobeInvalidCredentials,
            $status === 403 => ConnectorDiscoveryRunErrorCode::AdobeInsufficientPermissions,
            $status === 404, $status === 405 => ConnectorDiscoveryRunErrorCode::AdobeInvalidOrUnsupportedEndpoint,
            $status === 408 => ConnectorDiscoveryRunErrorCode::AdobeRequestTimeout,
            $status === 429 => ConnectorDiscoveryRunErrorCode::AdobeRateLimited,
            $status >= 500 && $status <= 599 => ConnectorDiscoveryRunErrorCode::AdobeVendorUnavailable,
            $status >= 400 && $status <= 499 => ConnectorDiscoveryRunErrorCode::AdobeUnrecognizedClientError,
            default => throw new \InvalidArgumentException('Unexpected HTTP status: '.$status),
        };

        $retryAfterSeconds = $status === 429
            ? RetryAfterHeaderNormalizer::normalize($headers)
            : null;

        return ConnectorDiscoveryAttemptResult::httpFailure($errorCode, $status, $retryAfterSeconds);
    }
}
