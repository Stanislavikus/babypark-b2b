<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use App\Support\Connectors\RetryAfterHeaderNormalizer;
use App\Support\Connectors\Transport\ConnectorHttpResult;

final class AdobePaaSConnectionCheckResponseMapper
{
    public const EXPECTED_ACL_RESOURCE = 'Magento_Catalog::products';

    private const PROBE_FAMILY = 'magento_products';

    public function map(#[\SensitiveParameter] ConnectorHttpResult $result): ConnectorConnectionCheckResult
    {
        if ($result->statusCode === 200) {
            return $this->mapSuccessResponse($result->body);
        }

        return $this->mapHttpResponse($result);
    }

    private function mapSuccessResponse(#[\SensitiveParameter] string $body): ConnectorConnectionCheckResult
    {
        if ($this->isValidSearchResultsBody($body)) {
            return ConnectorConnectionCheckResult::success();
        }

        return ConnectorConnectionCheckResult::httpFailure(
            ConnectorConnectionCheckErrorCode::AdobeUnexpectedResponse,
            200,
        );
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
    private function mapHttpResponse(#[\SensitiveParameter] ConnectorHttpResult $result): ConnectorConnectionCheckResult
    {
        $status = $result->statusCode;
        $structured = $this->classifyStructuredError($result->body);
        $errorCode = $structured['oauth_code'];

        if ($errorCode === null && in_array($status, [401, 403], true)
            && in_array(self::EXPECTED_ACL_RESOURCE, $structured['resources'], true)) {
            $errorCode = ConnectorConnectionCheckErrorCode::AdobeInsufficientPermissions;
        }

        $errorCode = match (true) {
            $errorCode instanceof ConnectorConnectionCheckErrorCode => $errorCode,
            $status >= 201 && $status <= 299 => ConnectorConnectionCheckErrorCode::AdobeUnexpectedSuccessStatus,
            $status >= 300 && $status <= 399 => ConnectorConnectionCheckErrorCode::AdobeRedirectResponse,
            $status === 400 => ConnectorConnectionCheckErrorCode::AdobeUnrecognizedBadRequest,
            $status === 401, $status === 403 => ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError,
            $status === 404, $status === 405 => ConnectorConnectionCheckErrorCode::AdobeInvalidOrUnsupportedEndpoint,
            $status === 408 => ConnectorConnectionCheckErrorCode::AdobeRequestTimeout,
            $status === 429 => ConnectorConnectionCheckErrorCode::AdobeRateLimited,
            $status >= 500 && $status <= 599 => ConnectorConnectionCheckErrorCode::AdobeVendorUnavailable,
            $status >= 400 && $status <= 499 => ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError,
            default => throw new \InvalidArgumentException('Unexpected HTTP status: '.$status),
        };

        $retryAfterSeconds = $status === 429
            ? RetryAfterHeaderNormalizer::normalize($result->headers)
            : null;

        return ConnectorConnectionCheckResult::httpFailure(
            $errorCode,
            $status,
            $retryAfterSeconds,
            $this->vendorRequestId($result->headers),
            self::PROBE_FAMILY,
            self::EXPECTED_ACL_RESOURCE,
            $structured['resources'],
            $structured['oauth_problem'],
            $structured['shape'],
        );
    }

    /**
     * @return array{oauth_code: ?ConnectorConnectionCheckErrorCode, oauth_problem: ?string, resources: list<string>, shape: string}
     */
    private function classifyStructuredError(#[\SensitiveParameter] string $body): array
    {
        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['oauth_code' => null, 'oauth_problem' => null, 'resources' => [], 'shape' => $body === '' ? 'empty' : 'non_json'];
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return ['oauth_code' => null, 'oauth_problem' => null, 'resources' => [], 'shape' => 'unsupported_json'];
        }

        $oauthProblem = is_string($decoded['oauth_problem'] ?? null) ? $decoded['oauth_problem'] : null;
        $oauthCode = $this->oauthProblemCode($oauthProblem);
        $resources = [];
        $shape = 'generic_json';

        if (isset($decoded['parameters']) && is_array($decoded['parameters']) && ! array_is_list($decoded['parameters'])
            && array_key_exists('resources', $decoded['parameters'])) {
            $rawResources = $decoded['parameters']['resources'];

            if (is_string($rawResources) && $rawResources !== '') {
                $resources = [$rawResources];
                $shape = 'magento_acl_resource_string';
            } elseif ($this->isResourceList($rawResources)) {
                $resources = array_values(array_unique($rawResources));
                $shape = 'magento_acl_resource_list';
            } else {
                $shape = 'unsupported_acl_resources';
            }
        }

        if ($oauthCode !== null) {
            $shape = 'recognized_oauth_problem';
        }

        return ['oauth_code' => $oauthCode, 'oauth_problem' => $oauthCode === null ? null : $oauthProblem, 'resources' => $resources, 'shape' => $shape];
    }

    private function oauthProblemCode(?string $problem): ?ConnectorConnectionCheckErrorCode
    {
        return match ($problem) {
            'consumer_key_invalid' => ConnectorConnectionCheckErrorCode::AdobeOAuthConsumerKeyInvalid,
            'consumer_key_rejected' => ConnectorConnectionCheckErrorCode::AdobeOAuthConsumerKeyRejected,
            'signature_invalid' => ConnectorConnectionCheckErrorCode::AdobeOAuthSignatureInvalid,
            'nonce_used' => ConnectorConnectionCheckErrorCode::AdobeOAuthNonceUsed,
            'token_used' => ConnectorConnectionCheckErrorCode::AdobeOAuthTokenUsed,
            'token_expired' => ConnectorConnectionCheckErrorCode::AdobeOAuthTokenExpired,
            'token_revoke' => ConnectorConnectionCheckErrorCode::AdobeOAuthTokenRevoke,
            'token_rejected' => ConnectorConnectionCheckErrorCode::AdobeOAuthTokenRejected,
            'verifier_invalid' => ConnectorConnectionCheckErrorCode::AdobeOAuthVerifierInvalid,
            'permission_unknown' => ConnectorConnectionCheckErrorCode::AdobeOAuthPermissionUnknown,
            'permission_denied' => ConnectorConnectionCheckErrorCode::AdobeOAuthPermissionDenied,
            default => null,
        };
    }

    private function isResourceList(mixed $resources): bool
    {
        if (! is_array($resources) || ! array_is_list($resources)) {
            return false;
        }

        foreach ($resources as $resource) {
            if (! is_string($resource) || $resource === '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, list<string>> $headers */
    private function vendorRequestId(array $headers): ?string
    {
        foreach (['X-Request-Id', 'X-Adobe-Request-Id', 'X-Magento-Request-Id'] as $name) {
            foreach ($headers as $headerName => $values) {
                if (strcasecmp($name, $headerName) === 0 && isset($values[0]) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $values[0]) === 1) {
                    return $values[0];
                }
            }
        }

        return null;
    }
}
