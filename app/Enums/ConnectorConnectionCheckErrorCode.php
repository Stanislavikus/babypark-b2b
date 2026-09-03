<?php

namespace App\Enums;

enum ConnectorConnectionCheckErrorCode: string
{
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
        return match ($this) {
            self::AdobeOAuthTimestampRefused,
            self::AdobeOAuthSignatureMethodRejected,
            self::AdobeOAuthNonceUsed,
            self::AdobeOAuthSignatureInvalid,
            self::AdobeOAuthConsumerKeyRejected,
            self::AdobeOAuthTokenUsed,
            self::AdobeOAuthTokenExpired,
            self::AdobeOAuthTokenRevoke,
            self::AdobeOAuthTokenRejected,
            self::AdobeOAuthVerifierInvalid,
            self::AdobeOAuthConsumerKeyInvalid,
            self::AdobeInvalidCredentials => ConnectorErrorCause::Authentication,

            self::AdobeOAuthPermissionUnknown,
            self::AdobeOAuthPermissionDenied,
            self::AdobeInsufficientPermissions => ConnectorErrorCause::Authorization,

            self::AdobeOAuthMethodNotAllowed,
            self::AdobeRedirectResponse,
            self::AdobeInvalidOrUnsupportedEndpoint,
            self::TransportInvalidDestination,
            self::TransportUnsafeDestination => ConnectorErrorCause::Configuration,

            self::AdobeRequestTimeout,
            self::TransportDnsResolutionFailed,
            self::TransportTimeout,
            self::TransportConnectionFailed,
            self::TransportTlsVerificationFailed => ConnectorErrorCause::Network,

            self::AdobeRateLimited => ConnectorErrorCause::RateLimit,

            self::AdobeVendorUnavailable => ConnectorErrorCause::VendorUnavailable,

            self::AdobeUnexpectedResponse => ConnectorErrorCause::SchemaValidation,
            self::TransportResponseSizeExceeded => ConnectorErrorCause::SchemaValidation,

            self::AdobeOAuthVersionRejected,
            self::AdobeOAuthParameterAbsent,
            self::AdobeOAuthParameterRejected,
            self::AdobeUnexpectedSuccessStatus,
            self::AdobeUnrecognizedBadRequest,
            self::AdobeUnrecognizedClientError,
            self::TransportChildProcessProtocolFailed,
            self::TransportChildProcessCleanupFailed,
            self::TransportOtherFailure => ConnectorErrorCause::Unknown,
        };
    }

    public function actionability(): ConnectorErrorActionability
    {
        return match ($this) {
            self::AdobeOAuthTimestampRefused,
            self::AdobeOAuthSignatureMethodRejected,
            self::AdobeOAuthNonceUsed,
            self::AdobeOAuthSignatureInvalid,
            self::AdobeOAuthConsumerKeyRejected,
            self::AdobeOAuthTokenUsed,
            self::AdobeOAuthTokenExpired,
            self::AdobeOAuthTokenRevoke,
            self::AdobeOAuthTokenRejected,
            self::AdobeOAuthVerifierInvalid,
            self::AdobeOAuthConsumerKeyInvalid,
            self::AdobeOAuthPermissionUnknown,
            self::AdobeOAuthPermissionDenied,
            self::AdobeOAuthMethodNotAllowed,
            self::AdobeRedirectResponse,
            self::AdobeInvalidCredentials,
            self::AdobeInsufficientPermissions,
            self::AdobeInvalidOrUnsupportedEndpoint,
            self::TransportInvalidDestination,
            self::TransportUnsafeDestination => ConnectorErrorActionability::UserActionRequired,

            self::AdobeRequestTimeout,
            self::AdobeRateLimited,
            self::AdobeVendorUnavailable,
            self::TransportDnsResolutionFailed,
            self::TransportTimeout,
            self::TransportConnectionFailed => ConnectorErrorActionability::AutomaticRetry,

            self::AdobeUnexpectedResponse,
            self::AdobeUnexpectedSuccessStatus,
            self::AdobeUnrecognizedBadRequest,
            self::AdobeUnrecognizedClientError,
            self::AdobeOAuthVersionRejected,
            self::AdobeOAuthParameterAbsent,
            self::AdobeOAuthParameterRejected,
            self::TransportTlsVerificationFailed,
            self::TransportResponseSizeExceeded,
            self::TransportChildProcessProtocolFailed,
            self::TransportChildProcessCleanupFailed,
            self::TransportOtherFailure => ConnectorErrorActionability::SupportRequired,
        };
    }

    public function messageKey(): string
    {
        return match ($this) {
            self::AdobeOAuthTimestampRefused,
            self::AdobeOAuthSignatureMethodRejected,
            self::AdobeOAuthNonceUsed,
            self::AdobeOAuthSignatureInvalid => 'connectors.errors.invalid_signature',

            self::AdobeOAuthConsumerKeyRejected,
            self::AdobeOAuthTokenUsed,
            self::AdobeOAuthTokenExpired,
            self::AdobeOAuthTokenRevoke,
            self::AdobeOAuthTokenRejected,
            self::AdobeOAuthVerifierInvalid,
            self::AdobeOAuthConsumerKeyInvalid,
            self::AdobeInvalidCredentials => 'connectors.errors.invalid_credentials',

            self::AdobeOAuthPermissionUnknown,
            self::AdobeOAuthPermissionDenied,
            self::AdobeInsufficientPermissions => 'connectors.errors.insufficient_permissions',

            self::AdobeOAuthMethodNotAllowed,
            self::AdobeRedirectResponse,
            self::AdobeInvalidOrUnsupportedEndpoint,
            self::TransportInvalidDestination,
            self::TransportUnsafeDestination => 'connectors.errors.invalid_or_unsupported_endpoint',

            self::AdobeRequestTimeout => 'connectors.errors.timeout',

            self::AdobeRateLimited => 'connectors.errors.rate_limited',

            self::AdobeVendorUnavailable => 'connectors.errors.vendor_unavailable',

            self::AdobeUnexpectedResponse,
            self::AdobeUnexpectedSuccessStatus,
            self::TransportResponseSizeExceeded => 'connectors.errors.unexpected_response',

            self::AdobeOAuthVersionRejected,
            self::AdobeOAuthParameterAbsent,
            self::AdobeOAuthParameterRejected,
            self::AdobeUnrecognizedBadRequest,
            self::AdobeUnrecognizedClientError,
            self::TransportChildProcessProtocolFailed,
            self::TransportChildProcessCleanupFailed,
            self::TransportOtherFailure => 'connectors.errors.connection_check_failed',

            self::TransportDnsResolutionFailed,
            self::TransportTimeout,
            self::TransportConnectionFailed => 'connectors.errors.network_unavailable',

            self::TransportTlsVerificationFailed => 'connectors.errors.tls_verification_failed',
        };
    }

    public function isHttpFailure(): bool
    {
        return match ($this) {
            self::TransportInvalidDestination,
            self::TransportUnsafeDestination,
            self::TransportDnsResolutionFailed,
            self::TransportTimeout,
            self::TransportConnectionFailed,
            self::TransportTlsVerificationFailed,
            self::TransportResponseSizeExceeded,
            self::TransportChildProcessProtocolFailed,
            self::TransportChildProcessCleanupFailed,
            self::TransportOtherFailure => false,
            default => true,
        };
    }

    public function isTransportFailure(): bool
    {
        return ! $this->isHttpFailure();
    }

    public function acceptsHttpStatus(int $status): bool
    {
        return match ($this) {
            self::AdobeOAuthVersionRejected,
            self::AdobeOAuthParameterAbsent,
            self::AdobeOAuthParameterRejected,
            self::AdobeOAuthTimestampRefused,
            self::AdobeOAuthSignatureMethodRejected => $status === 400,
            self::AdobeOAuthNonceUsed,
            self::AdobeOAuthSignatureInvalid,
            self::AdobeOAuthConsumerKeyRejected,
            self::AdobeOAuthTokenUsed,
            self::AdobeOAuthTokenExpired,
            self::AdobeOAuthTokenRevoke,
            self::AdobeOAuthTokenRejected,
            self::AdobeOAuthVerifierInvalid => $status === 401,
            self::AdobeOAuthPermissionUnknown,
            self::AdobeOAuthPermissionDenied,
            self::AdobeOAuthConsumerKeyInvalid => $status === 403,
            self::AdobeOAuthMethodNotAllowed => $status === 405,
            self::AdobeUnexpectedSuccessStatus => $status >= 201 && $status <= 299,
            self::AdobeRedirectResponse => $status >= 300 && $status <= 399,
            self::AdobeUnrecognizedBadRequest => $status === 400,
            self::AdobeInvalidCredentials => $status === 401,
            self::AdobeInsufficientPermissions => $status === 401 || $status === 403,
            self::AdobeInvalidOrUnsupportedEndpoint => $status === 404 || $status === 405,
            self::AdobeRequestTimeout => $status === 408,
            self::AdobeRateLimited => $status === 429,
            self::AdobeVendorUnavailable => $status >= 500 && $status <= 599,
            self::AdobeUnexpectedResponse => $status === 200,
            self::AdobeUnrecognizedClientError => $status >= 400 && $status <= 499
                && ! in_array($status, [400, 404, 405, 408, 429], true),
            default => false,
        };
    }
}
