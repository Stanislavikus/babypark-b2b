<?php

namespace Tests\Unit\Connectors;

use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\CanonicalSchemaFieldHash;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\ConnectorDiscoveryNormalizedField;
use App\Support\Connectors\ConnectorDiscoverySnapshotCandidate;
use App\Support\Connectors\Transport\TimeoutPhase;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorDiscoveryAttemptResultTest extends TestCase
{
    #[Test]
    public function success_has_expected_invariants(): void
    {
        $candidate = $this->sampleCandidate();

        $result = ConnectorDiscoveryAttemptResult::success($candidate);

        $this->assertTrue($result->succeeded);
        $this->assertNull($result->httpStatus);
        $this->assertNull($result->errorCode);
        $this->assertNull($result->timeoutPhase);
        $this->assertNull($result->retryAfterSeconds);
        $this->assertSame($candidate, $result->snapshotCandidate);
        $this->assertNull($result->cause());
        $this->assertNull($result->actionability());
        $this->assertNull($result->messageKey());
        $this->assertNull($result->technicalSummary());
    }

    #[Test]
    public function http_failure_stores_actual_status_and_derives_metadata(): void
    {
        $result = ConnectorDiscoveryAttemptResult::httpFailure(
            ConnectorDiscoveryRunErrorCode::AdobeInvalidCredentials,
            401,
        );

        $this->assertFalse($result->succeeded);
        $this->assertSame(401, $result->httpStatus);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::AdobeInvalidCredentials, $result->errorCode);
        $this->assertNull($result->timeoutPhase);
        $this->assertNull($result->retryAfterSeconds);
        $this->assertNull($result->snapshotCandidate);
        $this->assertSame(ConnectorErrorCause::Authentication, $result->cause());
        $this->assertSame(ConnectorErrorActionability::UserActionRequired, $result->actionability());
        $this->assertSame('connectors.errors.invalid_credentials', $result->messageKey());
        $this->assertSame('HTTP 401 (adobe_invalid_credentials)', $result->technicalSummary());
    }

    #[Test]
    public function transport_failure_has_null_http_status(): void
    {
        $result = ConnectorDiscoveryAttemptResult::transportFailure(
            ConnectorDiscoveryRunErrorCode::TransportTimeout,
            TimeoutPhase::Connect,
        );

        $this->assertFalse($result->succeeded);
        $this->assertNull($result->httpStatus);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::TransportTimeout, $result->errorCode);
        $this->assertSame(TimeoutPhase::Connect, $result->timeoutPhase);
        $this->assertSame('transport_timeout (connect phase)', $result->technicalSummary());
    }

    #[Test]
    public function schema_validation_failure_has_expected_invariants(): void
    {
        $result = ConnectorDiscoveryAttemptResult::schemaValidationFailure();

        $this->assertFalse($result->succeeded);
        $this->assertNull($result->httpStatus);
        $this->assertSame(
            ConnectorDiscoveryRunErrorCode::DiscoverySchemaValidationFailed,
            $result->errorCode,
        );
        $this->assertNull($result->timeoutPhase);
        $this->assertNull($result->retryAfterSeconds);
        $this->assertNull($result->snapshotCandidate);
        $this->assertSame(ConnectorErrorCause::SchemaValidation, $result->cause());
        $this->assertSame(ConnectorErrorActionability::SupportRequired, $result->actionability());
        $this->assertSame('connectors.errors.discovery_failed', $result->messageKey());
        $this->assertSame('discovery_schema_validation_failed', $result->technicalSummary());
    }

    #[Test]
    public function pagination_failure_has_expected_invariants(): void
    {
        $result = ConnectorDiscoveryAttemptResult::paginationFailure(
            ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination,
        );

        $this->assertFalse($result->succeeded);
        $this->assertNull($result->httpStatus);
        $this->assertSame(
            ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination,
            $result->errorCode,
        );
        $this->assertSame(ConnectorErrorCause::SchemaValidation, $result->cause());
        $this->assertSame(ConnectorErrorActionability::SupportRequired, $result->actionability());
        $this->assertSame('discovery_incomplete_pagination', $result->technicalSummary());
    }

    #[Test]
    public function http_failure_rejects_transport_category_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Error code does not accept this HTTP status.');

        ConnectorDiscoveryAttemptResult::httpFailure(
            ConnectorDiscoveryRunErrorCode::TransportTimeout,
            408,
        );
    }

    #[Test]
    public function transport_failure_rejects_http_category_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a transport-failure error code.');

        ConnectorDiscoveryAttemptResult::transportFailure(
            ConnectorDiscoveryRunErrorCode::AdobeInvalidCredentials,
        );
    }

    #[Test]
    public function pagination_failure_rejects_non_pagination_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid pagination error code.');

        ConnectorDiscoveryAttemptResult::paginationFailure(
            ConnectorDiscoveryRunErrorCode::DiscoverySchemaValidationFailed,
        );
    }

    #[Test]
    public function http_failure_accepts_retry_after_only_for_rate_limited_429(): void
    {
        $result = ConnectorDiscoveryAttemptResult::httpFailure(
            ConnectorDiscoveryRunErrorCode::AdobeRateLimited,
            429,
            120,
        );

        $this->assertSame(120, $result->retryAfterSeconds);
    }

    #[Test]
    public function http_failure_rejects_retry_after_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConnectorDiscoveryAttemptResult::httpFailure(
            ConnectorDiscoveryRunErrorCode::AdobeRateLimited,
            429,
            301,
        );
    }

    private function sampleCandidate(): ConnectorDiscoverySnapshotCandidate
    {
        $normalizer = new AdobePaaSAttributeNormalizer;
        $fieldHasher = new CanonicalSchemaFieldHasher;
        $snapshotHasher = new CanonicalSchemaSnapshotHasher;

        $raw = json_decode(
            '{"attribute_code":"color","frontend_input":"text","scope":"global"}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );
        $canonicalField = $normalizer->normalize($raw);
        $normalizedField = new ConnectorDiscoveryNormalizedField(
            $canonicalField,
            $fieldHasher->hash($canonicalField),
        );

        return ConnectorDiscoverySnapshotCandidate::create(
            [$normalizedField],
            $snapshotHasher->hash([
                CanonicalSchemaFieldHash::create(
                    $canonicalField->externalFieldKey(),
                    $fieldHasher->hash($canonicalField),
                ),
            ]),
            CarbonImmutable::now(),
            1,
        );
    }
}
