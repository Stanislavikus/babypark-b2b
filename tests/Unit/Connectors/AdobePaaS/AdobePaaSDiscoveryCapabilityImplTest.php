<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Enums\ConnectorErrorActionability;
use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapabilityImpl;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryRequestFactory;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryResponseMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryTransportMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\ConnectorSchemaSourceEndpointPathValidator;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class AdobePaaSDiscoveryCapabilityImplTest extends TestCase
{
    private const ENDPOINT_PATH = '/V1/products/attributes';

    #[Test]
    public function sends_request_with_discovery_limits_and_exact_pagination_query(): void
    {
        $context = $this->sampleContext();
        $requestFactory = new AdobePaaSDiscoveryRequestFactory(
            new OAuth1RequestSigner,
            new ConnectorSchemaSourceEndpointPathValidator,
        );
        $referenceRequest = $requestFactory->build(
            $context,
            self::ENDPOINT_PATH,
            1,
            new OAuth1SigningContext('fixednonce00000001', 1_700_000_000),
        );

        $transport = new class($referenceRequest) implements ConnectorHttpTransport
        {
            public int $sendCount = 0;

            public ?ConnectorOutboundRequest $captured = null;

            public function __construct(private readonly RequestInterface $referenceRequest) {}

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->sendCount++;
                $this->captured = $request;

                return new ConnectorHttpResult(200, [], $this->pageBody([$this->attribute('color')], 1));
            }

            private function attribute(string $code): \stdClass
            {
                return json_decode(
                    sprintf('{"attribute_code":"%s","frontend_input":"text","scope":"global"}', $code),
                    associative: false,
                    depth: 512,
                    flags: JSON_THROW_ON_ERROR,
                );
            }

            private function pageBody(array $items, int $totalCount): string
            {
                return json_encode([
                    'items' => $items,
                    'total_count' => $totalCount,
                ], JSON_THROW_ON_ERROR);
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($context, self::ENDPOINT_PATH);

        $this->assertTrue($result->succeeded);
        $this->assertSame(1, $transport->sendCount);
        $this->assertNotNull($transport->captured);
        $this->assertSame('GET', $transport->captured->request->getMethod());
        $this->assertSame((string) $referenceRequest->getUri(), (string) $transport->captured->request->getUri());
        $this->assertStringContainsString('searchCriteria%5BpageSize%5D=200', (string) $transport->captured->request->getUri());
        $this->assertStringContainsString('searchCriteria%5BcurrentPage%5D=1', (string) $transport->captured->request->getUri());
        $this->assertStringContainsString('oauth_consumer_key="ck_test"', $transport->captured->request->getHeaderLine('Authorization'));
        $this->assertSame(10.0, $transport->captured->limits->connectTimeoutSeconds);
        $this->assertSame(60.0, $transport->captured->limits->totalTimeoutSeconds);
        $this->assertSame(2 * 1024 * 1024, $transport->captured->limits->maxResponseBodyBytes);
    }

    #[Test]
    public function paginates_until_total_count_is_reached_without_exceeding_fifty_requests(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public int $sendCount = 0;

            /** @var list<int> */
            public array $pagesRequested = [];

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->sendCount++;
                parse_str((string) $request->request->getUri()->getQuery(), $query);
                $currentPage = (int) ($query['searchCriteria']['currentPage'] ?? 0);
                $this->pagesRequested[] = $currentPage;

                $items = [];
                for ($index = 0; $index < 200; $index++) {
                    $offset = (($currentPage - 1) * 200) + $index;
                    if ($offset >= 400) {
                        break;
                    }

                    $items[] = $this->attribute('field_'.$offset);
                }

                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => $items,
                    'total_count' => 400,
                ], JSON_THROW_ON_ERROR));
            }

            private function attribute(string $code): \stdClass
            {
                return json_decode(
                    sprintf('{"attribute_code":"%s","frontend_input":"text","scope":"global"}', $code),
                    associative: false,
                    depth: 512,
                    flags: JSON_THROW_ON_ERROR,
                );
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertTrue($result->succeeded);
        $this->assertSame(2, $transport->sendCount);
        $this->assertSame([1, 2], $transport->pagesRequested);
        $this->assertSame(400, $result->snapshotCandidate?->fieldsReceived());
    }

    #[Test]
    public function does_not_issue_fifty_first_request_when_total_count_exceeds_field_limit(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public int $sendCount = 0;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->sendCount++;

                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [],
                    'total_count' => 10001,
                ], JSON_THROW_ON_ERROR));
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertFalse($result->succeeded);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::DiscoveryPaginationLimitExceeded, $result->errorCode);
        $this->assertSame(1, $transport->sendCount);
    }

    #[Test]
    public function malformed_json_maps_to_unexpected_response_failure(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], '{not-json');
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertFalse($result->succeeded);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::AdobeUnexpectedResponse, $result->errorCode);
        $this->assertSame(200, $result->httpStatus);
    }

    #[Test]
    public function schema_validation_failure_stops_on_first_invalid_item(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public int $sendCount = 0;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->sendCount++;

                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [
                        json_decode('{"attribute_code":"valid","frontend_input":"text","scope":"global"}', false, 512, JSON_THROW_ON_ERROR),
                        json_decode('{"frontend_input":"text","scope":"global"}', false, 512, JSON_THROW_ON_ERROR),
                    ],
                    'total_count' => 2,
                ], JSON_THROW_ON_ERROR));
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertFalse($result->succeeded);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::DiscoverySchemaValidationFailed, $result->errorCode);
        $this->assertSame(1, $transport->sendCount);
    }

    #[Test]
    public function total_count_drift_returns_incomplete_pagination_failure(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public int $page = 0;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->page++;

                if ($this->page === 1) {
                    return new ConnectorHttpResult(200, [], json_encode([
                        'items' => [json_decode('{"attribute_code":"a","frontend_input":"text","scope":"global"}', false, 512, JSON_THROW_ON_ERROR)],
                        'total_count' => 2,
                    ], JSON_THROW_ON_ERROR));
                }

                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [json_decode('{"attribute_code":"b","frontend_input":"text","scope":"global"}', false, 512, JSON_THROW_ON_ERROR)],
                    'total_count' => 3,
                ], JSON_THROW_ON_ERROR));
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertFalse($result->succeeded);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination, $result->errorCode);
        $this->assertSame(2, $transport->page);
    }

    #[Test]
    public function rate_limited_response_caps_retry_after_at_three_hundred_seconds(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(429, ['Retry-After' => ['400']], '');
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertFalse($result->succeeded);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::AdobeRateLimited, $result->errorCode);
        $this->assertSame(429, $result->httpStatus);
        $this->assertSame(300, $result->retryAfterSeconds);
    }

    #[Test]
    public function transport_exception_maps_to_result(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                throw new ConnectorTransportException(TransportFailureReason::Timeout);
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertFalse($result->succeeded);
        $this->assertNull($result->httpStatus);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::TransportTimeout, $result->errorCode);
    }

    #[Test]
    public function empty_page_before_total_count_is_reached_returns_incomplete_pagination(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [],
                    'total_count' => 5,
                ], JSON_THROW_ON_ERROR));
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertFalse($result->succeeded);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination, $result->errorCode);
    }

    #[Test]
    public function later_page_total_count_above_limit_takes_precedence_over_stable_count(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public int $page = 0;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->page++;

                if ($this->page === 1) {
                    return new ConnectorHttpResult(200, [], json_encode([
                        'items' => [json_decode('{"attribute_code":"a","frontend_input":"text","scope":"global"}', false, 512, JSON_THROW_ON_ERROR)],
                        'total_count' => 2,
                    ], JSON_THROW_ON_ERROR));
                }

                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [json_decode('{"attribute_code":"b","frontend_input":"text","scope":"global"}', false, 512, JSON_THROW_ON_ERROR)],
                    'total_count' => 10001,
                ], JSON_THROW_ON_ERROR));
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertFalse($result->succeeded);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::DiscoveryPaginationLimitExceeded, $result->errorCode);
        $this->assertSame(2, $transport->page);
    }

    #[Test]
    public function fiftieth_page_boundary_returns_limit_exceeded_without_fifty_first_request(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public int $sendCount = 0;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->sendCount++;
                parse_str((string) $request->request->getUri()->getQuery(), $query);
                $currentPage = (int) ($query['searchCriteria']['currentPage'] ?? 0);

                $items = [];
                $itemCount = $currentPage < 50 ? 200 : 100;

                for ($index = 0; $index < $itemCount; $index++) {
                    $items[] = $this->attribute('field_'.$currentPage.'_'.$index);
                }

                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => $items,
                    'total_count' => 10000,
                ], JSON_THROW_ON_ERROR));
            }

            private function attribute(string $code): \stdClass
            {
                return json_decode(
                    sprintf('{"attribute_code":"%s","frontend_input":"text","scope":"global"}', $code),
                    associative: false,
                    depth: 512,
                    flags: JSON_THROW_ON_ERROR,
                );
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertFalse($result->succeeded);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::DiscoveryPaginationLimitExceeded, $result->errorCode);
        $this->assertSame(50, $transport->sendCount);
    }

    #[Test]
    public function pagination_order_checks_per_page_limit_before_stable_total_count(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [],
                    'total_count' => 10001,
                ], JSON_THROW_ON_ERROR));
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertFalse($result->succeeded);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::DiscoveryPaginationLimitExceeded, $result->errorCode);
        $this->assertNotSame(ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination, $result->errorCode);
    }

    #[Test]
    public function duplicate_external_field_keys_return_schema_validation_failure(): void
    {
        $attribute = json_decode(
            '{"attribute_code":"color","frontend_input":"text","scope":"global"}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $transport = new class($attribute) implements ConnectorHttpTransport
        {
            public function __construct(private readonly \stdClass $attribute) {}

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [$this->attribute, $this->attribute],
                    'total_count' => 2,
                ], JSON_THROW_ON_ERROR));
            }
        };

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertFalse($result->succeeded);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::DiscoverySchemaValidationFailed, $result->errorCode);
        $this->assertSame(ConnectorErrorActionability::SupportRequired, $result->actionability());
        $this->assertNull($result->snapshotCandidate);
    }

    #[Test]
    public function invalid_endpoint_path_never_reaches_transport(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public int $sendCount = 0;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->sendCount++;

                return new ConnectorHttpResult(200, [], '{"items":[],"total_count":0}');
            }
        };

        $capability = $this->capabilityWithTransport($transport);

        try {
            $capability->discover($this->sampleContext(), '//evil.example.com/V1/products');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, $transport->sendCount);
        }
    }

    private function capabilityWithTransport(ConnectorHttpTransport $transport): AdobePaaSDiscoveryCapabilityImpl
    {
        return new AdobePaaSDiscoveryCapabilityImpl(
            new AdobePaaSDiscoveryRequestFactory(
                new OAuth1RequestSigner,
                new ConnectorSchemaSourceEndpointPathValidator,
            ),
            $transport,
            new AdobePaaSDiscoveryResponseMapper,
            new AdobePaaSDiscoveryTransportMapper,
            new AdobePaaSAttributeNormalizer,
            new CanonicalSchemaFieldHasher,
            new CanonicalSchemaSnapshotHasher,
        );
    }

    private function sampleContext(): AdobePaaSRequestContext
    {
        return new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: new OAuth1Credentials('ck_test', 'cs_test', 'at_test', 'ts_test'),
        );
    }
}
