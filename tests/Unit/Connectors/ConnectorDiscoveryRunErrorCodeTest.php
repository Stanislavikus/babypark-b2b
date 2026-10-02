<?php

namespace Tests\Unit\Connectors;

use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorDiscoveryRunErrorCodeTest extends TestCase
{
    #[Test]
    public function case_count_matches_shared_plus_three_discovery_specific(): void
    {
        $this->assertSame(
            count(ConnectorConnectionCheckErrorCode::cases()) + 3,
            count(ConnectorDiscoveryRunErrorCode::cases()),
        );
    }

    #[Test]
    public function shared_cases_delegate_classification_to_connection_check_error_code(): void
    {
        foreach (ConnectorConnectionCheckErrorCode::cases() as $shared) {
            $discovery = ConnectorDiscoveryRunErrorCode::from($shared->value);

            $this->assertSame($shared->name, $discovery->name);
            $this->assertSame($shared->cause(), $discovery->cause());
            $this->assertSame($shared->actionability(), $discovery->actionability());
            $this->assertSame($shared->messageKey(), $discovery->messageKey());
            $this->assertSame($shared->isHttpFailure(), $discovery->isHttpFailure());
            $this->assertSame($shared->isTransportFailure(), $discovery->isTransportFailure());

            foreach (range(100, 599) as $status) {
                $this->assertSame(
                    $shared->acceptsHttpStatus($status),
                    $discovery->acceptsHttpStatus($status),
                    "{$shared->name} differs for HTTP {$status}",
                );
            }
        }
    }

    #[Test]
    public function discovery_specific_cases_have_expected_backing_values(): void
    {
        $this->assertSame(
            'discovery_pagination_limit_exceeded',
            ConnectorDiscoveryRunErrorCode::DiscoveryPaginationLimitExceeded->value,
        );
        $this->assertSame(
            'discovery_incomplete_pagination',
            ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination->value,
        );
        $this->assertSame(
            'discovery_schema_validation_failed',
            ConnectorDiscoveryRunErrorCode::DiscoverySchemaValidationFailed->value,
        );
    }

    #[Test]
    #[DataProvider('discoverySpecificCaseProvider')]
    public function discovery_specific_cases_have_expected_classification(
        ConnectorDiscoveryRunErrorCode $case,
        int $status,
    ): void {
        $this->assertSame(ConnectorErrorCause::SchemaValidation, $case->cause());
        $this->assertSame(ConnectorErrorActionability::SupportRequired, $case->actionability());
        $this->assertSame('connectors.errors.discovery_failed', $case->messageKey());
        $this->assertFalse($case->isHttpFailure());
        $this->assertFalse($case->isTransportFailure());
        $this->assertFalse($case->acceptsHttpStatus($status));
    }

    /**
     * @return iterable<string, array{0: ConnectorDiscoveryRunErrorCode, 1: int}>
     */
    public static function discoverySpecificCaseProvider(): iterable
    {
        $statuses = [100, 200, 400, 404, 429, 500, 503, 599];

        foreach (ConnectorDiscoveryRunErrorCode::cases() as $case) {
            if (ConnectorConnectionCheckErrorCode::tryFrom($case->value) !== null) {
                continue;
            }

            foreach ($statuses as $status) {
                yield "{$case->name}_status_{$status}" => [$case, $status];
            }
        }
    }

    #[Test]
    public function automatic_retry_cases_are_exactly_the_six_shared_cases(): void
    {
        $automaticRetryCases = array_filter(
            ConnectorDiscoveryRunErrorCode::cases(),
            static fn (ConnectorDiscoveryRunErrorCode $case) => $case->actionability() === ConnectorErrorActionability::AutomaticRetry,
        );

        $expected = [
            ConnectorDiscoveryRunErrorCode::AdobeRequestTimeout,
            ConnectorDiscoveryRunErrorCode::AdobeRateLimited,
            ConnectorDiscoveryRunErrorCode::AdobeVendorUnavailable,
            ConnectorDiscoveryRunErrorCode::TransportDnsResolutionFailed,
            ConnectorDiscoveryRunErrorCode::TransportTimeout,
            ConnectorDiscoveryRunErrorCode::TransportConnectionFailed,
        ];

        $this->assertCount(6, $automaticRetryCases);
        $this->assertSame($expected, array_values($automaticRetryCases));
    }

    #[Test]
    public function gateway_outcomes_map_to_adobe_vendor_unavailable_not_a_separate_code(): void
    {
        $this->assertTrue(ConnectorDiscoveryRunErrorCode::AdobeVendorUnavailable->acceptsHttpStatus(500));
        $this->assertTrue(ConnectorDiscoveryRunErrorCode::AdobeVendorUnavailable->acceptsHttpStatus(503));
        $this->assertTrue(ConnectorDiscoveryRunErrorCode::AdobeVendorUnavailable->acceptsHttpStatus(599));

        foreach (range(500, 599) as $status) {
            foreach (ConnectorDiscoveryRunErrorCode::cases() as $case) {
                if ($case === ConnectorDiscoveryRunErrorCode::AdobeVendorUnavailable) {
                    continue;
                }

                $this->assertFalse(
                    $case->acceptsHttpStatus($status),
                    "Case {$case->name} accepts HTTP {$status} — no separate gateway code should exist",
                );
            }
        }
    }
}
