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

class AdobePaaSOAuthNonceDiscoveryIntegrationTest extends TestCase
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
    public function fresh_oauth_nonce_is_issued_for_each_paginated_discovery_page(): void
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

        $result = $this->discoverWithTransport($transport);

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
}
