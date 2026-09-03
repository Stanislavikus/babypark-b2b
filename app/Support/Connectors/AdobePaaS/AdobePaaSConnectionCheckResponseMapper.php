<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use App\Support\Connectors\RetryAfterHeaderNormalizer;
use App\Support\Connectors\Transport\ConnectorHttpResult;

final class AdobePaaSConnectionCheckResponseMapper
{
    public function map(#[\SensitiveParameter] ConnectorHttpResult $result): ConnectorConnectionCheckResult
    {
        if ($result->statusCode === 200) {
            return $this->mapSuccessResponse($result->body);
        }

        return $this->mapHttpStatus($result->statusCode, $result->headers);
    }

    private function mapSuccessResponse(#[\SensitiveParameter] string $body): ConnectorConnectionCheckResult
    {
        if ($this->isValidSearchResultsBody($body)) {
            return ConnectorConnectionCheckResult::success();
        }

        return ConnectorConnectionCheckResult::httpFailure(ConnectorConnectionCheckErrorCode::AdobeUnexpectedResponse, 200);
    }

    private function isValidSearchResultsBody(#[\SensitiveParameter] string $body): bool
    {
        try {
            $decoded = json_decode($body, associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (! $decoded instanceof \stdClass) {
            return false;
        }

        if (! is_array($decoded->items ?? null)) {
            return false;
        }

        if (! ($decoded->search_criteria ?? null) instanceof \stdClass) {
            return false;
        }

        if (! is_int($decoded->total_count ?? null) || $decoded->total_count < 0) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function mapHttpStatus(int $status, array $headers): ConnectorConnectionCheckResult
    {
        $errorCode = match (true) {
            $status >= 201 && $status <= 299 => ConnectorConnectionCheckErrorCode::AdobeUnexpectedSuccessStatus,
            $status >= 300 && $status <= 399 => ConnectorConnectionCheckErrorCode::AdobeRedirectResponse,
            $status === 400 => ConnectorConnectionCheckErrorCode::AdobeUnrecognizedBadRequest,
            $status === 401 => ConnectorConnectionCheckErrorCode::AdobeInvalidCredentials,
            $status === 403 => ConnectorConnectionCheckErrorCode::AdobeInsufficientPermissions,
            $status === 404, $status === 405 => ConnectorConnectionCheckErrorCode::AdobeInvalidOrUnsupportedEndpoint,
            $status === 408 => ConnectorConnectionCheckErrorCode::AdobeRequestTimeout,
            $status === 429 => ConnectorConnectionCheckErrorCode::AdobeRateLimited,
            $status >= 500 && $status <= 599 => ConnectorConnectionCheckErrorCode::AdobeVendorUnavailable,
            $status >= 400 && $status <= 499 => ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError,
            default => throw new \InvalidArgumentException('Unexpected HTTP status: '.$status),
        };

        $retryAfterSeconds = $status === 429
            ? RetryAfterHeaderNormalizer::normalize($headers)
            : null;

        return ConnectorConnectionCheckResult::httpFailure($errorCode, $status, $retryAfterSeconds);
    }
}
