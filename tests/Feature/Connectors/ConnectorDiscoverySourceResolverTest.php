<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorSchemaAcquisitionMode;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Enums\ConnectorSchemaVerificationStatus;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSource;
use App\Services\Connectors\AdobePaaSDiscoveryService;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapability;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceInvalidAfterReservationException;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceResolutionException;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySourceResolutionReason;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorDiscoverySourceResolverTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    private ConnectorDiscoverySourceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);

        $this->resolver = app(ConnectorDiscoverySourceResolver::class);
    }

    #[Test]
    public function resolve_throws_missing_when_no_primary_live_fetch_account_api_source_exists(): void
    {
        $shopifyDefinition = ConnectorDefinition::query()->where('code', 'shopify')->firstOrFail();
        $account = $this->createConnectorAccount(overrides: [
            'connector_definition_id' => $shopifyDefinition->id,
        ]);

        try {
            $this->resolver->resolve($account);
            $this->fail('Expected ConnectorDiscoverySourceResolutionException');
        } catch (ConnectorDiscoverySourceResolutionException $exception) {
            $this->assertSame(ConnectorDiscoverySourceResolutionReason::Missing, $exception->reason);
            $this->assertSame(0, $exception->matchCount);
        }
    }

    #[Test]
    public function resolve_throws_ambiguous_when_multiple_primary_sources_match(): void
    {
        $definition = $this->adobeConnectorDefinition();
        $account = $this->createConnectorAccount();

        ConnectorSchemaSource::query()->create([
            'connector_definition_id' => $definition->id,
            'code' => 'duplicate_live_attributes_'.Str::random(4),
            'label' => 'Duplicate live attributes',
            'source_kind' => ConnectorSchemaSourceKind::AccountApi,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::LiveFetch,
            'schema_scope' => ConnectorSchemaScope::Account,
            'reference_url' => 'https://example.com/docs',
            'endpoint_path' => '/V1/products/attributes',
            'schema_version' => '2.4.9-admin',
            'is_primary' => true,
            'verification_status' => ConnectorSchemaVerificationStatus::Verified,
            'last_verified_at' => now(),
            'sort_order' => 99,
        ]);

        try {
            $this->resolver->resolve($account);
            $this->fail('Expected ConnectorDiscoverySourceResolutionException');
        } catch (ConnectorDiscoverySourceResolutionException $exception) {
            $this->assertSame(ConnectorDiscoverySourceResolutionReason::Ambiguous, $exception->reason);
            $this->assertSame(2, $exception->matchCount);
        }
    }

    #[Test]
    public function resolve_returns_single_valid_primary_source(): void
    {
        $account = $this->createConnectorAccount();

        $source = $this->resolver->resolve($account);

        $this->assertSame('live_account_attributes', $source->code);
        $this->assertSame('/V1/products/attributes', $source->endpoint_path);
        $this->assertTrue($source->is_primary);
        $this->assertSame(ConnectorSchemaScope::Account, $source->schema_scope);
        $this->assertSame(ConnectorSchemaSourceKind::AccountApi, $source->source_kind);
        $this->assertSame(ConnectorSchemaAcquisitionMode::LiveFetch, $source->acquisition_mode);
    }

    #[Test]
    public function invalid_endpoint_path_is_ignored_for_resolution(): void
    {
        $definition = $this->adobeConnectorDefinition();

        ConnectorSchemaSource::query()
            ->where('connector_definition_id', $definition->id)
            ->where('code', 'live_account_attributes')
            ->update(['endpoint_path' => 'https://evil.example/V1/products/attributes']);

        $account = $this->createConnectorAccount();

        try {
            $this->resolver->resolve($account);
            $this->fail('Expected ConnectorDiscoverySourceResolutionException');
        } catch (ConnectorDiscoverySourceResolutionException $exception) {
            $this->assertSame(ConnectorDiscoverySourceResolutionReason::Missing, $exception->reason);
            $this->assertSame(0, $exception->matchCount);
        }
    }

    #[Test]
    public function invalid_endpoint_path_blocks_discovery_before_transport(): void
    {
        $account = $this->createConnectorAccount();
        $row = $this->createQueuedRow($account);

        ConnectorSchemaSource::query()
            ->whereKey($row->connector_schema_source_id)
            ->update(['endpoint_path' => '/V1/%252e%252e/products']);

        $capability = Mockery::mock(AdobePaaSDiscoveryCapability::class);
        $capability->shouldNotReceive('discover');
        $this->app->instance(AdobePaaSDiscoveryCapability::class, $capability);

        try {
            app(AdobePaaSDiscoveryService::class)->execute($account->workspace_id, $account->id, $row->id);
            $this->fail('Expected ConnectorDiscoverySourceInvalidAfterReservationException');
        } catch (ConnectorDiscoverySourceInvalidAfterReservationException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createQueuedRow(ConnectorAccount $account, array $overrides = []): ConnectorDiscoveryRun
    {
        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);

        return ConnectorDiscoveryRun::withoutWorkspaceScope()->create(array_merge([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => 'manual',
            'status' => ConnectorDiscoveryRunStatus::Queued,
            'execution_attempts' => 0,
            'retry_until_at' => now()->addHour(),
            'next_attempt_at' => null,
            'started_at' => null,
        ], $overrides));
    }
}
