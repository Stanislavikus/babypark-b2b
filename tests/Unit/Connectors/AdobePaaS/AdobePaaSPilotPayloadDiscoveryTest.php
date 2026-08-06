<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapabilityImpl;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryRequestFactory;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryResponseMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryTransportMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSServiceOnlyAttributeEligibility;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\ConnectorSchemaSourceEndpointPathValidator;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Connectors\Fixtures\MagentoPilotAttributesDiscoveryFixture;
use Tests\TestCase;

class AdobePaaSPilotPayloadDiscoveryTest extends TestCase
{
    private const ENDPOINT_PATH = '/V1/products/attributes';

    #[Test]
    public function full_pilot_fixture_succeeds_with_divergent_received_and_normalized_counts(): void
    {
        $first = $this->discoverFixture();
        $second = $this->discoverFixture();

        $this->assertTrue($first->succeeded);
        $this->assertTrue($second->succeeded);
        $this->assertNotNull($first->snapshotCandidate);
        $this->assertNotNull($second->snapshotCandidate);

        $candidate = $first->snapshotCandidate;
        $this->assertSame(MagentoPilotAttributesDiscoveryFixture::RECEIVED_COUNT, $candidate->fieldsReceived());
        $this->assertSame(MagentoPilotAttributesDiscoveryFixture::NORMALIZED_COUNT, $candidate->fieldsNormalized());
        $this->assertSame($first->snapshotCandidate->canonicalHash, $second->snapshotCandidate->canonicalHash);

        $keys = array_map(
            fn ($field) => $field->field->externalFieldKey(),
            $candidate->fields,
        );

        foreach (MagentoPilotAttributesDiscoveryFixture::SERVICE_ONLY_ATTRIBUTE_CODES as $code) {
            $this->assertNotContains($code, $keys);
        }

        foreach (MagentoPilotAttributesDiscoveryFixture::REPRESENTATIVE_INVISIBLE_NORMALIZED_CODES as $code) {
            $this->assertContains($code, $keys);
        }
    }

    #[Test]
    public function paginated_fixture_counts_skipped_items_in_received_total_and_stops_after_full_total_count(): void
    {
        $fixture = MagentoPilotAttributesDiscoveryFixture::paginatedResponse();
        $transport = new class($fixture) implements ConnectorHttpTransport
        {
            public int $sendCount = 0;

            /** @var list<int> */
            public array $pagesRequested = [];

            /**
             * @param  array{pages: list<array{items: list<\stdClass>, total_count: int}>, total_count: int}  $fixture
             */
            public function __construct(private readonly array $fixture) {}

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->sendCount++;
                parse_str((string) $request->request->getUri()->getQuery(), $query);
                $currentPage = (int) ($query['searchCriteria']['currentPage'] ?? 0);
                $this->pagesRequested[] = $currentPage;
                $page = $this->fixture['pages'][$currentPage - 1] ?? ['items' => [], 'total_count' => $this->fixture['total_count']];

                return new ConnectorHttpResult(200, [], json_encode($page, JSON_THROW_ON_ERROR));
            }
        };

        $result = $this->capabilityWithTransport($transport)->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertTrue($result->succeeded);
        $this->assertSame(2, $transport->sendCount);
        $this->assertSame([1, 2], $transport->pagesRequested);
        $this->assertSame(MagentoPilotAttributesDiscoveryFixture::RECEIVED_COUNT, $result->snapshotCandidate?->fieldsReceived());
        $this->assertSame(MagentoPilotAttributesDiscoveryFixture::NORMALIZED_COUNT, $result->snapshotCandidate?->fieldsNormalized());
    }

    #[Test]
    public function null_frontend_input_with_visible_true_still_fails_schema_validation(): void
    {
        $items = MagentoPilotAttributesDiscoveryFixture::allItems();
        $items[0] = json_decode(
            '{"attribute_code":"visible_null_input","frontend_input":null,"scope":"global","is_user_defined":false,"is_visible":true}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $result = $this->discoverItems($items);

        $this->assertFalse($result->succeeded);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::DiscoverySchemaValidationFailed, $result->errorCode);
    }

    #[Test]
    public function discovery_with_skipped_attributes_emits_one_info_log_after_successful_pagination(): void
    {
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $result = $this->discoverFixture();

        $this->assertTrue($result->succeeded);

        $skipLogs = array_values(array_filter(
            $logged,
            static fn (MessageLogged $event): bool => $event->level === 'info'
                && str_contains($event->message, 'skipped service-only attributes'),
        ));

        $this->assertCount(1, $skipLogs);
        $context = $skipLogs[0]->context;
        $this->assertSame(4, $context['skipped_count']);
        $this->assertSame(MagentoPilotAttributesDiscoveryFixture::SERVICE_ONLY_ATTRIBUTE_CODES, $context['attribute_codes']);
        $this->assertArrayNotHasKey('items', $context);
        $this->assertArrayNotHasKey('credentials', $context);
    }

    #[Test]
    public function discovery_without_skipped_attributes_emits_no_skip_log(): void
    {
        $items = array_values(array_filter(
            MagentoPilotAttributesDiscoveryFixture::allItems(),
            static fn (\stdClass $item): bool => ! in_array(
                $item->attribute_code,
                MagentoPilotAttributesDiscoveryFixture::SERVICE_ONLY_ATTRIBUTE_CODES,
                true,
            ),
        ));

        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $result = $this->discoverItems($items);

        $this->assertTrue($result->succeeded);
        $this->assertSame(count($items), $result->snapshotCandidate?->fieldsReceived());
        $this->assertSame(count($items), $result->snapshotCandidate?->fieldsNormalized());

        $skipLogs = array_values(array_filter(
            $logged,
            static fn (MessageLogged $event): bool => str_contains($event->message, 'skipped service-only attributes'),
        ));

        $this->assertSame([], $skipLogs);
    }

    /**
     * @param  list<\stdClass>  $items
     */
    private function discoverItems(array $items): ConnectorDiscoveryAttemptResult
    {
        $transport = new class($items) implements ConnectorHttpTransport
        {
            /**
             * @param  list<\stdClass>  $items
             */
            public function __construct(private readonly array $items) {}

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => $this->items,
                    'total_count' => count($this->items),
                ], JSON_THROW_ON_ERROR));
            }
        };

        return $this->capabilityWithTransport($transport)->discover($this->sampleContext(), self::ENDPOINT_PATH);
    }

    private function discoverFixture(): ConnectorDiscoveryAttemptResult
    {
        return $this->discoverItems(MagentoPilotAttributesDiscoveryFixture::allItems());
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
            new AdobePaaSServiceOnlyAttributeEligibility,
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
