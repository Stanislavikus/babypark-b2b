<?php

namespace App\Enums;

enum ConnectorDiscoveryRunErrorCode: string
{
    case DiscoveryPaginationLimitExceeded = 'discovery_pagination_limit_exceeded';
    case DiscoveryIncompletePagination = 'discovery_incomplete_pagination';
    case DiscoverySchemaValidationFailed = 'discovery_schema_validation_failed';

    case AdobeOAuthVersionRejected = 'adobe_oauth_version_rejected';
    case AdobeOAuthParameterAbsent = 'adobe_oauth_parameter_absent';
    case AdobeOAuthParameterRejected = 'adobe_oauth_parameter_rejected';
    case AdobeOAuthTimestampRefused = 'adobe_oauth_timestamp_refused';
    case AdobeOAuthNonceUsed = 'adobe_oauth_nonce_used';
    case AdobeOAuthSignatureMethodRejected = 'adobe_oauth_signature_method_rejected';
    case AdobeOAuthSignatureInvalid = 'adobe_oauth_signature_invalid';
    case AdobeOAuthConsumerKeyRejected = 'adobe_oauth_consumer_key_rejected';
    case AdobeOAuthTokenUsed = 'adobe_oauth_token_used';
    case AdobeOAuthTokenExpired = 'adobe_oauth_token_expired';
    case AdobeOAuthTokenRevoke = 'adobe_oauth_token_revoke';
    case AdobeOAuthTokenRejected = 'adobe_oauth_token_rejected';
    case AdobeOAuthVerifierInvalid = 'adobe_oauth_verifier_invalid';
    case AdobeOAuthPermissionUnknown = 'adobe_oauth_permission_unknown';
    case AdobeOAuthPermissionDenied = 'adobe_oauth_permission_denied';
    case AdobeOAuthMethodNotAllowed = 'adobe_oauth_method_not_allowed';
    case AdobeOAuthConsumerKeyInvalid = 'adobe_oauth_consumer_key_invalid';

    case AdobeUnexpectedResponse = 'adobe_unexpected_response';
    case AdobeUnexpectedSuccessStatus = 'adobe_unexpected_success_status';
    case AdobeRedirectResponse = 'adobe_redirect_response';
    case AdobeUnrecognizedBadRequest = 'adobe_unrecognized_bad_request';
    case AdobeInvalidCredentials = 'adobe_invalid_credentials';
    case AdobeInsufficientPermissions = 'adobe_insufficient_permissions';
    case AdobeInvalidOrUnsupportedEndpoint = 'adobe_invalid_or_unsupported_endpoint';
    case AdobeRequestTimeout = 'adobe_request_timeout';
    case AdobeRateLimited = 'adobe_rate_limited';
    case AdobeVendorUnavailable = 'adobe_vendor_unavailable';
    case AdobeUnrecognizedClientError = 'adobe_unrecognized_client_error';

    case TransportInvalidDestination = 'transport_invalid_destination';
    case TransportUnsafeDestination = 'transport_unsafe_destination';
    case TransportDnsResolutionFailed = 'transport_dns_resolution_failed';
    case TransportTimeout = 'transport_timeout';
    case TransportConnectionFailed = 'transport_connection_failed';
    case TransportTlsVerificationFailed = 'transport_tls_verification_failed';
    case TransportResponseSizeExceeded = 'transport_response_size_exceeded';
    case TransportChildProcessProtocolFailed = 'transport_child_process_protocol_failed';
    case TransportChildProcessCleanupFailed = 'transport_child_process_cleanup_failed';
    case TransportOtherFailure = 'transport_other_failure';

    public function cause(): ConnectorErrorCause
    {
        $shared = $this->sharedCode();

        if ($shared !== null) {
            return $shared->cause();
        }

        return ConnectorErrorCause::SchemaValidation;
    }

    public function actionability(): ConnectorErrorActionability
    {
        $shared = $this->sharedCode();

        if ($shared !== null) {
            return $shared->actionability();
        }

        return ConnectorErrorActionability::SupportRequired;
    }

    public function messageKey(): string
    {
        $shared = $this->sharedCode();

        if ($shared !== null) {
            return $shared->messageKey();
        }

        return 'connectors.errors.discovery_failed';
    }

    public function isHttpFailure(): bool
    {
        $shared = $this->sharedCode();

        if ($shared !== null) {
            return $shared->isHttpFailure();
        }

        return false;
    }

    public function isTransportFailure(): bool
    {
        $shared = $this->sharedCode();

        if ($shared !== null) {
            return $shared->isTransportFailure();
        }

        return false;
    }

    public function acceptsHttpStatus(int $status): bool
    {
        $shared = $this->sharedCode();

        if ($shared !== null) {
            return $shared->acceptsHttpStatus($status);
        }

        return false;
    }

    private function sharedCode(): ?ConnectorConnectionCheckErrorCode
    {
        return ConnectorConnectionCheckErrorCode::tryFrom($this->value);
    }
}
