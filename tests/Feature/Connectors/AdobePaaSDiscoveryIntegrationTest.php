<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use App\Services\Connectors\ConnectorDiscoveryRunPersistence;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapabilityImpl;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryRequestFactory;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryResponseMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryTransportMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\ConnectorSchemaSourceEndpointPathValidator;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class AdobePaaSDiscoveryIntegrationTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    private const ENDPOINT_PATH = '/V1/products/attributes';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function oauth_o_auth_nonce_is_unique_per_paginated_discovery_page_request(): void
    {
        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request, int $sendCount): ConnectorHttpResult {
            parse_str((string) $request->request->getUri()->getQuery(), $query);
            $currentPage = (int) ($query['searchCriteria']['currentPage'] ?? 0);

            $items = match ($currentPage) {
                1 => [$this->attribute('color'), $this->attribute('size')],
                2 => [$this->attribute('weight')],
                default => [],
            };

            return new ConnectorHttpResult(200, [], json_encode([
                'items' => $items,
                'total_count' => 3,
            ], JSON_THROW_ON_ERROR));
        });

        $capability = $this->capabilityWithTransport($transport);
        $result = $capability->discover($this->sampleContext(), self::ENDPOINT_PATH);

        $this->assertTrue($result->succeeded);
        $this->assertSame(2, $transport->sendCount);
        $this->assertCount(2, $transport->recordedRequests);

        $pages = [];
        $nonces = [];

        foreach ($transport->recordedRequests as $recordedRequest) {
            parse_str((string) $recordedRequest->request->getUri()->getQuery(), $query);

            $pages[] = (int) ($query['searchCriteria']['currentPage'] ?? 0);
            $this->assertSame(200, (int) ($query['searchCriteria']['pageSize'] ?? 0));

            $authorizationHeader = $recordedRequest->request->getHeaderLine('Authorization');
            $this->assertStringContainsString('oauth_nonce="', $authorizationHeader);
            $nonces[] = $this->extractOAuthNonce($authorizationHeader);
        }

        $this->assertSame([1, 2], $pages);
        $this->assertCount(2, array_unique($nonces));
        $this->assertNotSame($nonces[0], $nonces[1]);
    }

    #[Test]
    public function canonical_canonical_hash_is_invariant_across_pagination_and_item_order(): void
    {
        $runA = $this->discoverWithTransport(new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            parse_str((string) $request->request->getUri()->getQuery(), $query);
            $currentPage = (int) ($query['searchCriteria']['currentPage'] ?? 0);

            $items = match ($currentPage) {
                1 => [$this->attribute('color'), $this->attribute('size')],
                2 => [$this->attribute('weight')],
                default => [],
            };

            return new ConnectorHttpResult(200, [], json_encode([
                'items' => $items,
                'total_count' => 3,
            ], JSON_THROW_ON_ERROR));
        }));

        $runB = $this->discoverWithTransport(new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            parse_str((string) $request->request->getUri()->getQuery(), $query);
            $currentPage = (int) ($query['searchCriteria']['currentPage'] ?? 0);

            $items = match ($currentPage) {
                1 => [$this->attribute('weight'), $this->attribute('color')],
                2 => [$this->attribute('size')],
                default => [],
            };

            return new ConnectorHttpResult(200, [], json_encode([
                'items' => $items,
                'total_count' => 3,
            ], JSON_THROW_ON_ERROR));
        }));

        $this->assertTrue($runA->succeeded);
        $this->assertTrue($runB->succeeded);

        $candidateA = $runA->snapshotCandidate;
        $candidateB = $runB->snapshotCandidate;

        $this->assertNotNull($candidateA);
        $this->assertNotNull($candidateB);

        $this->assertSame($candidateA->canonicalHash, $candidateB->canonicalHash);
        $this->assertSame($candidateA->fieldsReceived(), $candidateB->fieldsReceived());
        $this->assertSame($candidateA->fieldsNormalized(), $candidateB->fieldsNormalized());
        $this->assertSame(3, $candidateA->fieldsReceived());
        $this->assertSame(3, $candidateA->fieldsNormalized());

        $keysA = array_map(
            fn ($field): string => $field->field->externalFieldKey(),
            $candidateA->fields,
        );
        $keysB = array_map(
            fn ($field): string => $field->field->externalFieldKey(),
            $candidateB->fields,
        );

        sort($keysA);
        sort($keysB);
        $this->assertSame($keysA, $keysB);
        $this->assertSame(['color', 'size', 'weight'], $keysA);

        $hashesA = [];
        foreach ($candidateA->fields as $field) {
            $hashesA[$field->field->externalFieldKey()] = $field->canonicalHash;
        }

        $hashesB = [];
        foreach ($candidateB->fields as $field) {
            $hashesB[$field->field->externalFieldKey()] = $field->canonicalHash;
        }

        $this->assertSame($hashesA, $hashesB);

        $accountA = $this->createConnectorAccount();
        $accountB = $this->createConnectorAccount();
        $persistedHashA = $this->publishCandidate($accountA, $runA);
        $persistedHashB = $this->publishCandidate($accountB, $runB);

        $this->assertSame($candidateA->canonicalHash, $persistedHashA);
        $this->assertSame($candidateB->canonicalHash, $persistedHashB);
        $this->assertSame($persistedHashA, $persistedHashB);
    }

    private function discoverWithTransport(RecordingConnectorHttpTransport $transport): ConnectorDiscoveryAttemptResult
    {
        return $this->capabilityWithTransport($transport)->discover($this->sampleContext(), self::ENDPOINT_PATH);
    }

    private function publishCandidate(
        ConnectorAccount $account,
        ConnectorDiscoveryAttemptResult $result,
    ): string {
        $row = $this->createQueuedRow($account);

        app(ConnectorDiscoveryRunPersistence::class)->finalizeAfterVendorAttempt(
            $account->workspace_id,
            $account->id,
            $row->id,
            $result,
            10,
            now()->addHour(),
        );

        $snapshot = ConnectorSchemaSnapshot::withoutWorkspaceScope()
            ->whereKey($row->fresh()->snapshot_id)
            ->firstOrFail();

        return $snapshot->canonical_hash;
    }

    private function createQueuedRow(ConnectorAccount $account): ConnectorDiscoveryRun
    {
        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);

        return ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => 'manual',
            'status' => ConnectorDiscoveryRunStatus::Running,
            'execution_attempts' => 1,
            'retry_until_at' => now()->addHour(),
            'next_attempt_at' => null,
            'started_at' => now(),
        ]);
    }

    private function capabilityWithTransport(RecordingConnectorHttpTransport $transport): AdobePaaSDiscoveryCapabilityImpl
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

    private function attribute(string $code, string $frontendInput = 'text'): \stdClass
    {
        return json_decode(
            sprintf('{"attribute_code":"%s","frontend_input":"%s","scope":"global"}', $code, $frontendInput),
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function extractOAuthNonce(string $authorizationHeader): string
    {
        if (preg_match('/oauth_nonce="([^"]+)"/', $authorizationHeader, $matches) !== 1) {
            $this->fail('Authorization header missing oauth_nonce.');
        }

        return $matches[1];
    }
}
