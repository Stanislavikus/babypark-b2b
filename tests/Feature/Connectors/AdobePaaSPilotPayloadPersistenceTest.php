<?php

namespace Tests\Feature\Connectors;

use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\Workspace;
use App\Services\Connectors\DiscoverySmokeTestHarness;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Support\Connectors\Fixtures\MagentoPilotAttributesDiscoveryFixture;
use Tests\Support\Connectors\InteractsWithAdobePaaSDiscoveryIntegration;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class AdobePaaSPilotPayloadPersistenceTest extends TestCase
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
    public function persistence_stores_divergent_received_and_normalized_counts_with_normalized_field_count(): void
    {
        $account = $this->createConnectorAccount();
        $result = $this->discoverWithTransport(new RecordingConnectorHttpTransport(
            fn (ConnectorOutboundRequest $request): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                [],
                json_encode(MagentoPilotAttributesDiscoveryFixture::singlePageResponse(), JSON_THROW_ON_ERROR),
            ),
        ));

        $this->assertTrue($result->succeeded);

        $row = $this->createQueuedRow($account);
        $this->publishCandidateForRow($account, $row, $result);

        $row->refresh();
        $snapshot = ConnectorSchemaSnapshot::withoutWorkspaceScope()->findOrFail($row->snapshot_id);

        $this->assertSame(106, $row->fields_received);
        $this->assertSame(102, $row->fields_normalized);
        $this->assertSame(102, $snapshot->field_count);
        $this->assertSame(
            102,
            ConnectorSchemaSnapshotField::withoutWorkspaceScope()->where('snapshot_id', $snapshot->id)->count(),
        );
    }

    #[Test]
    public function smoke_harness_accepts_pilot_payload_persistence_with_divergent_counts(): void
    {
        $account = $this->createConnectorAccount();
        $source = app(DiscoverySmokeTestHarness::class)->resolveCanonicalSchemaSource(
            app(DiscoverySmokeTestHarness::class)->resolveAdobeDefinition(),
        );
        $result = $this->discoverWithTransport(new RecordingConnectorHttpTransport(
            fn (ConnectorOutboundRequest $request): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                [],
                json_encode(MagentoPilotAttributesDiscoveryFixture::singlePageResponse(), JSON_THROW_ON_ERROR),
            ),
        ));

        $accountBefore = new ConnectorAccount([
            'last_discovery_at' => null,
            'last_successful_discovery_at' => null,
        ]);
        $row = $this->createQueuedRow($account);
        $this->publishCandidateForRow($account, $row, $result);
        $row->refresh();

        $workspace = Workspace::query()->findOrFail($account->workspace_id);
        $evidence = app(DiscoverySmokeTestHarness::class)->validateSuccessfulRun(
            ConnectorDiscoveryRun::withoutWorkspaceScope()->findOrFail($row->id),
            $source,
            $accountBefore,
            $workspace,
            $account,
        );

        $this->assertSame(106, $evidence['run']->fields_received);
        $this->assertSame(102, $evidence['run']->fields_normalized);
        $this->assertSame(102, $evidence['snapshot']->field_count);
        $this->assertNotEmpty($evidence['snapshot']->canonical_hash);
    }
}
