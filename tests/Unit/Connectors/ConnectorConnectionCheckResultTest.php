<?php

namespace Tests\Unit\Connectors;

use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use App\Support\Connectors\Transport\TimeoutPhase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorConnectionCheckResultTest extends TestCase
{
    #[Test]
    public function success_has_expected_invariants(): void
    {
        $result = ConnectorConnectionCheckResult::success();

        $this->assertTrue($result->succeeded);
        $this->assertSame(200, $result->httpStatus);
        $this->assertNull($result->errorCode);
        $this->assertNull($result->timeoutPhase);
        $this->assertNull($result->vendorRequestId);
        $this->assertNull($result->cause());
        $this->assertNull($result->actionability());
        $this->assertNull($result->messageKey());
        $this->assertNull($result->technicalSummary());
        $this->assertSame([], $result->safeMessageParameters());
    }

    #[Test]
    public function http_failure_stores_actual_status_and_derives_metadata(): void
    {
        $result = ConnectorConnectionCheckResult::httpFailure(
            ConnectorConnectionCheckErrorCode::AdobeInvalidCredentials,
            401,
        );

        $this->assertFalse($result->succeeded);
        $this->assertSame(401, $result->httpStatus);
        $this->assertSame(ConnectorConnectionCheckErrorCode::AdobeInvalidCredentials, $result->errorCode);
        $this->assertNull($result->timeoutPhase);
        $this->assertNull($result->vendorRequestId);
        $this->assertSame(ConnectorErrorCause::Authentication, $result->cause());
        $this->assertSame(ConnectorErrorActionability::UserActionRequired, $result->actionability());
        $this->assertSame('connectors.errors.invalid_credentials', $result->messageKey());
        $this->assertSame('HTTP 401 (adobe_invalid_credentials)', $result->technicalSummary());
        $this->assertSame([], $result->safeMessageParameters());
    }

    #[Test]
    public function transport_failure_has_null_http_status(): void
    {
        $result = ConnectorConnectionCheckResult::transportFailure(
            ConnectorConnectionCheckErrorCode::TransportTimeout,
            TimeoutPhase::Connect,
        );

        $this->assertFalse($result->succeeded);
        $this->assertNull($result->httpStatus);
        $this->assertSame(ConnectorConnectionCheckErrorCode::TransportTimeout, $result->errorCode);
        $this->assertSame(TimeoutPhase::Connect, $result->timeoutPhase);
        $this->assertSame('transport_timeout (connect phase)', $result->technicalSummary());
    }

    #[Test]
    public function http_failure_rejects_transport_category_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Error code does not accept this HTTP status.');

        ConnectorConnectionCheckResult::httpFailure(
            ConnectorConnectionCheckErrorCode::TransportTimeout,
            408,
        );
    }

    #[Test]
    public function http_failure_rejects_status_mismatch_for_http_category_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Error code does not accept this HTTP status.');

        ConnectorConnectionCheckResult::httpFailure(
            ConnectorConnectionCheckErrorCode::AdobeInvalidCredentials,
            404,
        );
    }

    #[Test]
    public function http_failure_rejects_vendor_unavailable_at_wrong_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConnectorConnectionCheckResult::httpFailure(
            ConnectorConnectionCheckErrorCode::AdobeVendorUnavailable,
            200,
        );
    }

    #[Test]
    public function http_failure_rejects_status_below_valid_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP status.');

        ConnectorConnectionCheckResult::httpFailure(
            ConnectorConnectionCheckErrorCode::AdobeInvalidCredentials,
            0,
        );
    }

    #[Test]
    public function http_failure_rejects_status_above_valid_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP status.');

        ConnectorConnectionCheckResult::httpFailure(
            ConnectorConnectionCheckErrorCode::AdobeInvalidCredentials,
            600,
        );
    }

    #[Test]
    public function transport_failure_rejects_http_category_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a transport-failure error code.');

        ConnectorConnectionCheckResult::transportFailure(
            ConnectorConnectionCheckErrorCode::AdobeInvalidCredentials,
        );
    }

    #[Test]
    public function transport_failure_rejects_timeout_phase_for_non_timeout_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TimeoutPhase only applies to TransportTimeout.');

        ConnectorConnectionCheckResult::transportFailure(
            ConnectorConnectionCheckErrorCode::TransportConnectionFailed,
            TimeoutPhase::Connect,
        );
    }
}
