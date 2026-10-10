<?php

namespace Tests\Feature\Connectors;

use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Support\Connectors\InteractsWithAdobePaaSDiscoveryIntegration;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class AdobePaaSCanonicalHashDiscoveryIntegrationTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use InteractsWithAdobePaaSDiscoveryIntegration;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function canonical_hash_is_invariant_across_pagination_and_item_order(): void
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

        ksort($hashesA);
        ksort($hashesB);
        $this->assertSame($hashesA, $hashesB);

        $accountA = $this->createConnectorAccount();
        $accountB = $this->createConnectorAccount();
        $persistedHashA = $this->publishCandidate($accountA, $runA);
        $persistedHashB = $this->publishCandidate($accountB, $runB);

        $this->assertSame($candidateA->canonicalHash, $persistedHashA);
        $this->assertSame($candidateB->canonicalHash, $persistedHashB);
        $this->assertSame($persistedHashA, $persistedHashB);
    }
}
