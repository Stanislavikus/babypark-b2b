<?php

namespace Tests\Unit\Connectors;

use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorConnectionCheckErrorCodeTest extends TestCase
{
    #[Test]
    #[DataProvider('enumCaseProvider')]
    public function every_case_has_stable_metadata(
        ConnectorConnectionCheckErrorCode $case,
        ConnectorErrorCause $expectedCause,
        ConnectorErrorActionability $expectedActionability,
        string $expectedMessageKey,
        bool $expectedIsHttpFailure,
        ?int $acceptedStatus,
        ?int $rejectedStatus,
    ): void {
        $this->assertSame($case->value, $case->name !== '' ? $case->value : '');
        $this->assertSame($expectedCause, $case->cause());
        $this->assertSame($expectedActionability, $case->actionability());
        $this->assertSame($expectedMessageKey, $case->messageKey());
        $this->assertSame($expectedIsHttpFailure, $case->isHttpFailure());
        $this->assertSame(! $expectedIsHttpFailure, $case->isTransportFailure());

        if ($acceptedStatus !== null) {
            $this->assertTrue($case->acceptsHttpStatus($acceptedStatus));
        }

        if ($rejectedStatus !== null) {
            $this->assertFalse($case->acceptsHttpStatus($rejectedStatus));
        }
    }

    /**
     * @return iterable<string, array{
     *     0: ConnectorConnectionCheckErrorCode,
     *     1: ConnectorErrorCause,
     *     2: ConnectorErrorActionability,
     *     3: string,
     *     4: bool,
     *     5: ?int,
     *     6: ?int
     * }>
     */
    public static function enumCaseProvider(): iterable
    {
        yield 'adobe_oauth_version_rejected' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthVersionRejected,
            ConnectorErrorCause::Unknown,
            ConnectorErrorActionability::SupportRequired,
            'connectors.errors.connection_check_failed',
            true,
            400,
            401,
        ];

        yield 'adobe_oauth_parameter_absent' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthParameterAbsent,
            ConnectorErrorCause::Unknown,
            ConnectorErrorActionability::SupportRequired,
            'connectors.errors.connection_check_failed',
            true,
            400,
            403,
        ];

        yield 'adobe_oauth_parameter_rejected' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthParameterRejected,
            ConnectorErrorCause::Unknown,
            ConnectorErrorActionability::SupportRequired,
            'connectors.errors.connection_check_failed',
            true,
            400,
            500,
        ];

        yield 'adobe_oauth_timestamp_refused' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthTimestampRefused,
            ConnectorErrorCause::Authentication,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_signature',
            true,
            400,
            401,
        ];

        yield 'adobe_oauth_nonce_used' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthNonceUsed,
            ConnectorErrorCause::Authentication,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_signature',
            true,
            401,
            403,
        ];

        yield 'adobe_oauth_signature_method_rejected' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthSignatureMethodRejected,
            ConnectorErrorCause::Authentication,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_signature',
            true,
            400,
            401,
        ];

        yield 'adobe_oauth_signature_invalid' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthSignatureInvalid,
            ConnectorErrorCause::Authentication,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_signature',
            true,
            401,
            403,
        ];

        yield 'adobe_oauth_consumer_key_rejected' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthConsumerKeyRejected,
            ConnectorErrorCause::Authentication,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_credentials',
            true,
            401,
            403,
        ];

        yield 'adobe_oauth_token_used' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthTokenUsed,
            ConnectorErrorCause::Authentication,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_credentials',
            true,
            401,
            400,
        ];

        yield 'adobe_oauth_token_expired' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthTokenExpired,
            ConnectorErrorCause::Authentication,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_credentials',
            true,
            401,
            403,
        ];

        yield 'adobe_oauth_token_revoke' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthTokenRevoke,
            ConnectorErrorCause::Authentication,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_credentials',
            true,
            401,
            500,
        ];

        yield 'adobe_oauth_token_rejected' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthTokenRejected,
            ConnectorErrorCause::Authentication,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_credentials',
            true,
            401,
            404,
        ];

        yield 'adobe_oauth_verifier_invalid' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthVerifierInvalid,
            ConnectorErrorCause::Authentication,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_credentials',
            true,
            401,
            403,
        ];

        yield 'adobe_oauth_permission_unknown' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthPermissionUnknown,
            ConnectorErrorCause::Authorization,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.insufficient_permissions',
            true,
            403,
            401,
        ];

        yield 'adobe_oauth_permission_denied' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthPermissionDenied,
            ConnectorErrorCause::Authorization,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.insufficient_permissions',
            true,
            403,
            401,
        ];

        yield 'adobe_oauth_method_not_allowed' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthMethodNotAllowed,
            ConnectorErrorCause::Configuration,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_or_unsupported_endpoint',
            true,
            405,
            404,
        ];

        yield 'adobe_oauth_consumer_key_invalid' => [
            ConnectorConnectionCheckErrorCode::AdobeOAuthConsumerKeyInvalid,
            ConnectorErrorCause::Authentication,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_credentials',
            true,
            403,
            401,
        ];

        yield 'adobe_unexpected_response' => [
            ConnectorConnectionCheckErrorCode::AdobeUnexpectedResponse,
            ConnectorErrorCause::SchemaValidation,
            ConnectorErrorActionability::SupportRequired,
            'connectors.errors.unexpected_response',
            true,
            200,
            201,
        ];

        yield 'adobe_unexpected_success_status' => [
            ConnectorConnectionCheckErrorCode::AdobeUnexpectedSuccessStatus,
            ConnectorErrorCause::Unknown,
            ConnectorErrorActionability::SupportRequired,
            'connectors.errors.unexpected_response',
            true,
            204,
            200,
        ];

        yield 'adobe_redirect_response' => [
            ConnectorConnectionCheckErrorCode::AdobeRedirectResponse,
            ConnectorErrorCause::Configuration,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_or_unsupported_endpoint',
            true,
            302,
            200,
        ];

        yield 'adobe_unrecognized_bad_request' => [
            ConnectorConnectionCheckErrorCode::AdobeUnrecognizedBadRequest,
            ConnectorErrorCause::Unknown,
            ConnectorErrorActionability::SupportRequired,
            'connectors.errors.connection_check_failed',
            true,
            400,
            401,
        ];

        yield 'adobe_invalid_credentials' => [
            ConnectorConnectionCheckErrorCode::AdobeInvalidCredentials,
            ConnectorErrorCause::Authentication,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_credentials',
            true,
            401,
            403,
        ];

        yield 'adobe_insufficient_permissions' => [
            ConnectorConnectionCheckErrorCode::AdobeInsufficientPermissions,
            ConnectorErrorCause::Authorization,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.insufficient_permissions',
            true,
            403,
            401,
        ];

        yield 'adobe_invalid_or_unsupported_endpoint' => [
            ConnectorConnectionCheckErrorCode::AdobeInvalidOrUnsupportedEndpoint,
            ConnectorErrorCause::Configuration,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_or_unsupported_endpoint',
            true,
            404,
            403,
        ];

        yield 'adobe_request_timeout' => [
            ConnectorConnectionCheckErrorCode::AdobeRequestTimeout,
            ConnectorErrorCause::Network,
            ConnectorErrorActionability::AutomaticRetry,
            'connectors.errors.timeout',
            true,
            408,
            429,
        ];

        yield 'adobe_rate_limited' => [
            ConnectorConnectionCheckErrorCode::AdobeRateLimited,
            ConnectorErrorCause::RateLimit,
            ConnectorErrorActionability::AutomaticRetry,
            'connectors.errors.rate_limited',
            true,
            429,
            408,
        ];

        yield 'adobe_vendor_unavailable' => [
            ConnectorConnectionCheckErrorCode::AdobeVendorUnavailable,
            ConnectorErrorCause::VendorUnavailable,
            ConnectorErrorActionability::AutomaticRetry,
            'connectors.errors.vendor_unavailable',
            true,
            500,
            400,
        ];

        yield 'adobe_unrecognized_client_error' => [
            ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError,
            ConnectorErrorCause::Unknown,
            ConnectorErrorActionability::SupportRequired,
            'connectors.errors.connection_check_failed',
            true,
            418,
            404,
        ];

        yield 'transport_invalid_destination' => [
            ConnectorConnectionCheckErrorCode::TransportInvalidDestination,
            ConnectorErrorCause::Configuration,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_or_unsupported_endpoint',
            false,
            null,
            null,
        ];

        yield 'transport_unsafe_destination' => [
            ConnectorConnectionCheckErrorCode::TransportUnsafeDestination,
            ConnectorErrorCause::Configuration,
            ConnectorErrorActionability::UserActionRequired,
            'connectors.errors.invalid_or_unsupported_endpoint',
            false,
            null,
            null,
        ];

        yield 'transport_dns_resolution_failed' => [
            ConnectorConnectionCheckErrorCode::TransportDnsResolutionFailed,
            ConnectorErrorCause::Network,
            ConnectorErrorActionability::AutomaticRetry,
            'connectors.errors.network_unavailable',
            false,
            null,
            null,
        ];

        yield 'transport_timeout' => [
            ConnectorConnectionCheckErrorCode::TransportTimeout,
            ConnectorErrorCause::Network,
            ConnectorErrorActionability::AutomaticRetry,
            'connectors.errors.network_unavailable',
            false,
            null,
            null,
        ];

        yield 'transport_connection_failed' => [
            ConnectorConnectionCheckErrorCode::TransportConnectionFailed,
            ConnectorErrorCause::Network,
            ConnectorErrorActionability::AutomaticRetry,
            'connectors.errors.network_unavailable',
            false,
            null,
            null,
        ];

        yield 'transport_tls_verification_failed' => [
            ConnectorConnectionCheckErrorCode::TransportTlsVerificationFailed,
            ConnectorErrorCause::Network,
            ConnectorErrorActionability::SupportRequired,
            'connectors.errors.tls_verification_failed',
            false,
            null,
            null,
        ];

        yield 'transport_response_size_exceeded' => [
            ConnectorConnectionCheckErrorCode::TransportResponseSizeExceeded,
            ConnectorErrorCause::SchemaValidation,
            ConnectorErrorActionability::SupportRequired,
            'connectors.errors.unexpected_response',
            false,
            null,
            null,
        ];

        yield 'transport_child_process_protocol_failed' => [
            ConnectorConnectionCheckErrorCode::TransportChildProcessProtocolFailed,
            ConnectorErrorCause::Unknown,
            ConnectorErrorActionability::SupportRequired,
            'connectors.errors.connection_check_failed',
            false,
            null,
            null,
        ];

        yield 'transport_child_process_cleanup_failed' => [
            ConnectorConnectionCheckErrorCode::TransportChildProcessCleanupFailed,
            ConnectorErrorCause::Unknown,
            ConnectorErrorActionability::SupportRequired,
            'connectors.errors.connection_check_failed',
            false,
            null,
            null,
        ];

        yield 'transport_other_failure' => [
            ConnectorConnectionCheckErrorCode::TransportOtherFailure,
            ConnectorErrorCause::Unknown,
            ConnectorErrorActionability::SupportRequired,
            'connectors.errors.connection_check_failed',
            false,
            null,
            null,
        ];
    }

    #[Test]
    public function transport_cases_reject_all_http_statuses(): void
    {
        foreach (ConnectorConnectionCheckErrorCode::cases() as $case) {
            if ($case->isTransportFailure()) {
                $this->assertFalse($case->acceptsHttpStatus(200));
                $this->assertFalse($case->acceptsHttpStatus(401));
                $this->assertFalse($case->acceptsHttpStatus(500));
            }
        }
    }
}
