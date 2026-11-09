<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Enums\ConnectorSchemaFieldNormalizationStatus;
use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapabilityImpl;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryRequestFactory;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryResponseMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryTransportMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSServiceOnlyAttributeEligibility;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\ConnectorSchemaSourceEndpointPathValidator;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Connectors\Fixtures\MagentoPilotAttributesDiscoveryFixture;
use Tests\TestCase;

class AdobePaaSPilotPayloadDiscoveryTest extends TestCase
{
    private const ENDPOINT_PATH = '/V1/products/attributes';

    #[Test]
    public function full_pilot_fixture_persists_all_identified_fields_and_quarantines_service_only_rows(): void
    {
        $first = $this->discoverFixture();
        $second = $this->discoverFixture();

        $this->assertTrue($first->succeeded);
        $this->assertTrue($second->succeeded);
        $candidate = $first->snapshotCandidate;
        $this->assertNotNull($candidate);
        $this->assertSame(MagentoPilotAttributesDiscoveryFixture::RECEIVED_COUNT, $candidate->fieldsReceived());
        $this->assertSame(MagentoPilotAttributesDiscoveryFixture::RECEIVED_COUNT, $candidate->fieldsIdentified());
        $this->assertSame(MagentoPilotAttributesDiscoveryFixture::NORMALIZED_COUNT, $candidate->fieldsNormalized());
        $this->assertSame(4, $candidate->fieldsUnclassified());
        $this->assertCount(MagentoPilotAttributesDiscoveryFixture::RECEIVED_COUNT, $candidate->fields);
        $this->assertSame('v2', $candidate->canonicalHashVersion());
        $this->assertSame($first->snapshotCandidate?->canonicalHash, $second->snapshotCandidate?->canonicalHash);

        $byKey = [];
        foreach ($candidate->fields as $field) {
            $byKey[$field->field->externalFieldKey()] = $field->field;
        }

        foreach (MagentoPilotAttributesDiscoveryFixture::SERVICE_ONLY_ATTRIBUTE_CODES as $code) {
            $this->assertArrayHasKey($code, $byKey);
            $this->assertSame(ConnectorSchemaFieldNormalizationStatus::Unclassified, $byKey[$code]->normalizationStatus());
            $this->assertNull($byKey[$code]->normalizationFailureReason());
            $this->assertNull($byKey[$code]->normalizedDataType());
        }

        foreach (MagentoPilotAttributesDiscoveryFixture::REPRESENTATIVE_INVISIBLE_NORMALIZED_CODES as $code) {
            $this->assertSame(ConnectorSchemaFieldNormalizationStatus::Normalized, $byKey[$code]->normalizationStatus());
        }
    }

    #[Test]
    public function paginated_fixture_keeps_received_identified_normalized_and_unclassified_counts_distinct(): void
    {
        $fixture = MagentoPilotAttributesDiscoveryFixture::paginatedResponse();
        $transport = new class($fixture) implements ConnectorHttpTransport
        {
            public int $sendCount = 0;

            public array $pagesRequested = [];

            public function __construct(private readonly array $fixture) {}

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->sendCount++;
                parse_str((string) $request->request->getUri()->getQuery(), $query);
                $currentPage = (int) ($query['searchCriteria']['currentPage'] ?? 0);
                $this->pagesRequested[] = $currentPage;
                $page = $this->fixture['pages'][$currentPage - 1]
                    ?? ['items' => [], 'total_count' => $this->fixture['total_count']];

                return new ConnectorHttpResult(200, [], json_encode($page, JSON_THROW_ON_ERROR));
            }
        };

        $result = $this->capabilityWithTransport($transport)->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertTrue($result->succeeded);
        $this->assertSame(2, $transport->sendCount);
        $this->assertSame([1, 2], $transport->pagesRequested);
        $this->assertSame(106, $result->snapshotCandidate?->fieldsReceived());
        $this->assertSame(106, $result->snapshotCandidate?->fieldsIdentified());
        $this->assertSame(102, $result->snapshotCandidate?->fieldsNormalized());
        $this->assertSame(4, $result->snapshotCandidate?->fieldsUnclassified());
    }

    #[Test]
    public function trustworthy_unknown_semantics_are_quarantined_without_failing_the_other_fields(): void
    {
        $items = MagentoPilotAttributesDiscoveryFixture::allItems();
        $items[0] = json_decode(
            '{"attribute_code":"visible_null_input","frontend_input":null,"scope":"global","is_user_defined":false,"is_visible":true,"backend_type":"varchar","apply_to":[],"validation_rules":[],"is_unique":"0"}',
            false,
            512,
            JSON_THROW_ON_ERROR,
        );

        $result = $this->discoverItems($items);

        $this->assertTrue($result->succeeded);
        $this->assertSame(106, $result->snapshotCandidate?->fieldsIdentified());
        $this->assertSame(101, $result->snapshotCandidate?->fieldsNormalized());
        $this->assertSame(5, $result->snapshotCandidate?->fieldsUnclassified());

        $field = collect($result->snapshotCandidate?->fields ?? [])
            ->first(fn ($row) => $row->field->externalFieldKey() === 'visible_null_input')?->field;
        $this->assertNotNull($field);
        $this->assertSame(ConnectorSchemaFieldNormalizationStatus::Unclassified, $field->normalizationStatus());
        $this->assertSame(ConnectorDiscoverySchemaValidationReason::InvalidType, $field->normalizationFailureReason());
        $this->assertSame('varchar', $field->normalizedPayload()->toCanonicalObject()->provider_metadata->backend_type);
    }

    #[Test]
    public function removing_service_only_rows_leaves_all_remaining_identified_rows_normalized(): void
    {
        $items = array_values(array_filter(
            MagentoPilotAttributesDiscoveryFixture::allItems(),
            static fn (\stdClass $item): bool => ! in_array(
                $item->attribute_code,
                MagentoPilotAttributesDiscoveryFixture::SERVICE_ONLY_ATTRIBUTE_CODES,
                true,
            ),
        ));

        $result = $this->discoverItems($items);

        $this->assertTrue($result->succeeded);
        $this->assertSame(count($items), $result->snapshotCandidate?->fieldsReceived());
        $this->assertSame(count($items), $result->snapshotCandidate?->fieldsIdentified());
        $this->assertSame(count($items), $result->snapshotCandidate?->fieldsNormalized());
        $this->assertSame(0, $result->snapshotCandidate?->fieldsUnclassified());
    }

    /** @param list<\stdClass> $items */
    private function discoverItems(array $items): ConnectorDiscoveryAttemptResult
    {
        $transport = new class($items) implements ConnectorHttpTransport
        {
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
            new AdobePaaSDiscoveryRequestFactory(new OAuth1RequestSigner, new ConnectorSchemaSourceEndpointPathValidator),
            $transport,
            new AdobePaaSDiscoveryResponseMapper,
            new AdobePaaSDiscoveryTransportMapper,
            new AdobePaaSAttributeNormalizer,
            new AdobePaaSServiceOnlyAttributeEligibility,
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
