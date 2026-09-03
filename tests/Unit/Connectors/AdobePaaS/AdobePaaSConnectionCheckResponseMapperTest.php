<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckResponseMapper;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobePaaSConnectionCheckResponseMapperTest extends TestCase
{
    private AdobePaaSConnectionCheckResponseMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new AdobePaaSConnectionCheckResponseMapper;
    }

    #[Test]
    public function maps_429_with_retry_after_header(): void
    {
        $result = $this->mapper->map(new ConnectorHttpResult(429, ['Retry-After' => ['90']], ''));

        $this->assertSame(ConnectorConnectionCheckErrorCode::AdobeRateLimited, $result->errorCode);
        $this->assertSame(90, $result->retryAfterSeconds);
    }

    #[Test]
    public function valid_empty_items_body_is_success(): void
    {
        $body = json_encode([
            'items' => [],
            'search_criteria' => new \stdClass,
            'total_count' => 0,
        ], JSON_THROW_ON_ERROR);

        $result = $this->mapper->map(new ConnectorHttpResult(200, [], $body));

        $this->assertTrue($result->succeeded);
        $this->assertSame(200, $result->httpStatus);
        $this->assertNull($result->errorCode);
    }

    #[Test]
    public function malformed_json_at_200_maps_to_unexpected_response(): void
    {
        $result = $this->mapper->map(new ConnectorHttpResult(200, [], 'not-json'));

        $this->assertFalse($result->succeeded);
        $this->assertSame(ConnectorConnectionCheckErrorCode::AdobeUnexpectedResponse, $result->errorCode);
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame(ConnectorErrorCause::SchemaValidation, $result->cause());
        $this->assertSame(ConnectorErrorActionability::SupportRequired, $result->actionability());
        $this->assertSame('connectors.errors.unexpected_response', $result->messageKey());
    }

    #[Test]
    public function wrong_shaped_json_at_200_maps_to_unexpected_response(): void
    {
        $result = $this->mapper->map(new ConnectorHttpResult(200, [], '{"items":"not-array"}'));

        $this->assertSame(ConnectorConnectionCheckErrorCode::AdobeUnexpectedResponse, $result->errorCode);
    }

    #[Test]
    #[DataProvider('b7AndFallbackStatusProvider')]
    public function maps_http_status_to_expected_error_code(
        int $status,
        ConnectorConnectionCheckErrorCode $expectedCode,
        string $expectedMessageKey,
    ): void {
        $result = $this->mapper->map(new ConnectorHttpResult($status, [], ''));

        $this->assertFalse($result->succeeded);
        $this->assertSame($expectedCode, $result->errorCode);
        $this->assertSame($status, $result->httpStatus);
        $this->assertSame($expectedMessageKey, $result->messageKey());
    }

    /**
     * @return iterable<string, array{0: int, 1: ConnectorConnectionCheckErrorCode, 2: string}>
     */
    public static function b7AndFallbackStatusProvider(): iterable
    {
        yield 'unknown 401' => [401, ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError, 'connectors.errors.connection_check_failed'];
        yield 'unknown 403' => [403, ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError, 'connectors.errors.connection_check_failed'];
        yield '404' => [404, ConnectorConnectionCheckErrorCode::AdobeInvalidOrUnsupportedEndpoint, 'connectors.errors.invalid_or_unsupported_endpoint'];
        yield '408' => [408, ConnectorConnectionCheckErrorCode::AdobeRequestTimeout, 'connectors.errors.timeout'];
        yield '429' => [429, ConnectorConnectionCheckErrorCode::AdobeRateLimited, 'connectors.errors.rate_limited'];
        yield '500' => [500, ConnectorConnectionCheckErrorCode::AdobeVendorUnavailable, 'connectors.errors.vendor_unavailable'];
        yield '302 redirect' => [302, ConnectorConnectionCheckErrorCode::AdobeRedirectResponse, 'connectors.errors.invalid_or_unsupported_endpoint'];
        yield '204 other 2xx' => [204, ConnectorConnectionCheckErrorCode::AdobeUnexpectedSuccessStatus, 'connectors.errors.unexpected_response'];
        yield 'unrecognized 400' => [400, ConnectorConnectionCheckErrorCode::AdobeUnrecognizedBadRequest, 'connectors.errors.connection_check_failed'];
        yield '405 without oauth identifier' => [405, ConnectorConnectionCheckErrorCode::AdobeInvalidOrUnsupportedEndpoint, 'connectors.errors.invalid_or_unsupported_endpoint'];
        yield 'other 4xx 418' => [418, ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError, 'connectors.errors.connection_check_failed'];
    }

    #[Test]
    #[DataProvider('oauthProblemSentenceProvider')]
    public function oauth_problem_sentences_fall_through_to_http_status_fallback(
        string $body,
        int $status,
        ConnectorConnectionCheckErrorCode $expectedCode,
    ): void {
        $result = $this->mapper->map(new ConnectorHttpResult($status, [], $body));

        $this->assertSame($expectedCode, $result->errorCode);
        $this->assertSame($status, $result->httpStatus);
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: ConnectorConnectionCheckErrorCode}>
     */
    public static function oauthProblemSentenceProvider(): iterable
    {
        yield 'signature sentence at 401' => [
            'oauth_problem=The+signature+is+invalid.+Verify+and+try+again.',
            401,
            ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError,
        ];

        yield 'consumer key expired sentence at 401' => [
            'oauth_problem=Consumer+key+has+expired',
            401,
            ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError,
        ];
    }

    #[Test]
    #[DataProvider('structuredProtectedRestErrorProvider')]
    public function classifies_only_certified_machine_readable_protected_rest_errors(
        int $status,
        string $body,
        ConnectorConnectionCheckErrorCode $expectedCode,
        string $expectedShape,
    ): void {
        $result = $this->mapper->map(new ConnectorHttpResult($status, ['X-Request-Id' => ['req-123']], $body));

        $this->assertSame($expectedCode, $result->errorCode);
        $this->assertSame($status, $result->httpStatus);
        $this->assertSame($expectedShape, $result->responseShape);
        $this->assertSame('Magento_Catalog::products', $result->expectedAclResource);
        $this->assertSame('req-123', $result->vendorRequestId);
    }

    public static function structuredProtectedRestErrorProvider(): iterable
    {
        $acl = static fn (mixed $resources): string => json_encode([
            'message' => 'localized and deliberately ignored',
            'parameters' => ['resources' => $resources],
        ], JSON_THROW_ON_ERROR);

        yield '401 Product ACL string' => [401, $acl('Magento_Catalog::products'), ConnectorConnectionCheckErrorCode::AdobeInsufficientPermissions, 'magento_acl_resource_string'];
        yield '403 Product ACL among list' => [403, $acl(['Magento_Backend::admin', 'Magento_Catalog::products']), ConnectorConnectionCheckErrorCode::AdobeInsufficientPermissions, 'magento_acl_resource_list'];
        yield 'invalid consumer wins over ACL' => [403, json_encode(['oauth_problem' => 'consumer_key_invalid', 'parameters' => ['resources' => 'Magento_Catalog::products']], JSON_THROW_ON_ERROR), ConnectorConnectionCheckErrorCode::AdobeOAuthConsumerKeyInvalid, 'recognized_oauth_problem'];
        yield 'invalid signature' => [401, '{"oauth_problem":"signature_invalid"}', ConnectorConnectionCheckErrorCode::AdobeOAuthSignatureInvalid, 'recognized_oauth_problem'];
        yield 'permission denied' => [403, '{"oauth_problem":"permission_denied"}', ConnectorConnectionCheckErrorCode::AdobeOAuthPermissionDenied, 'recognized_oauth_problem'];
        yield 'permission unknown' => [403, '{"oauth_problem":"permission_unknown"}', ConnectorConnectionCheckErrorCode::AdobeOAuthPermissionUnknown, 'recognized_oauth_problem'];
        yield 'HTML WAF' => [403, '<html>denied</html>', ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError, 'non_json'];
        yield 'generic JSON' => [403, '{"error":"forbidden"}', ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError, 'generic_json'];
        yield 'malformed JSON' => [403, '{', ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError, 'non_json'];
        yield 'unrelated ACL' => [403, $acl('Magento_Catalog::attributes_attributes'), ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError, 'magento_acl_resource_string'];
        yield 'unsupported ACL map' => [403, $acl(['resource' => 'Magento_Catalog::products']), ConnectorConnectionCheckErrorCode::AdobeUnrecognizedClientError, 'unsupported_acl_resources'];
    }

    #[Test]
    #[DataProvider('malformedInputProvider')]
    public function malformed_input_falls_through_without_throwing(string $body, int $status): void
    {
        $result = $this->mapper->map(new ConnectorHttpResult($status, [], $body));

        $this->assertFalse($result->succeeded);
        $this->assertNotNull($result->errorCode);
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function malformedInputProvider(): iterable
    {
        yield 'empty body 401' => ['', 401];
        yield 'unrelated json 401' => ['{"message":"Consumer isn\'t authorized"}', 401];
        yield 'json missing message field 403' => ['{"code":403}', 403];
    }
}
