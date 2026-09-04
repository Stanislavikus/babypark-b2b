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
        yield '401 invalid credentials' => [401, ConnectorConnectionCheckErrorCode::AdobeInvalidCredentials, 'connectors.errors.invalid_credentials'];
        yield '403 insufficient permissions' => [403, ConnectorConnectionCheckErrorCode::AdobeInsufficientPermissions, 'connectors.errors.insufficient_permissions'];
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
