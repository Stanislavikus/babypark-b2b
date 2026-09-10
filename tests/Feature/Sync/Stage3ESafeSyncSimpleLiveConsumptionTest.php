<?php

namespace Tests\Feature\Sync;

use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandInput;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use App\Support\Sync\Live\SyncLiveConsequentialWriteGate;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeProductCommandTestFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class Stage3ESafeSyncSimpleLiveConsumptionTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use CreatesMerchantConfirmedExternalRecordLinks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function trusted_simple_product_uses_entity_bound_safe_sync_exactly_once(): void
    {
        $transport = new RecordingConnectorHttpTransport(
            static fn () => new ConnectorHttpResult(200, [], json_encode([
                'applied_state' => 'known_applied',
                'reason_code' => 'safe_sync_known_applied',
                'logical_entity_id' => 77,
                'sku' => 'SKU-TEST-1',
                'postcondition_verified' => true,
                'consequential_write_attempts' => 1,
                'warning_codes' => [],
            ], JSON_THROW_ON_ERROR)),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        [$workspace, $account, $variant] = $this->trustedVariant('77');
        $executor = $this->app->make(AdobeProductSimpleCommandExecutor::class);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
            ]),
            adobeBaseCurrency: 'UAH',
            consequentialWriteGate: $this->gate(true),
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame(1, $transport->sendCount);
        $this->assertSame(1, $result->evidence->consequentialWriteAttempts);
        $this->assertTrue($result->evidence->ownershipTrustSatisfied);

        $request = $transport->recordedRequests[0]->request;
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame(
            'https://shop.example.com/rest/default/V1/safe-sync/products/77',
            (string) $request->getUri(),
        );
        $this->assertStringNotContainsString('/V1/products/SKU-TEST-1', (string) $request->getUri());

        $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('SKU-TEST-1', $payload['request']['expected_sku'] ?? null);
        $this->assertSame('Test Product', $payload['request']['name'] ?? null);
        $this->assertSame(1, $payload['request']['status'] ?? null);
        $this->assertSame(4, $payload['request']['visibility'] ?? null);
        $this->assertEquals(100.0, $payload['request']['price'] ?? null);
    }

    #[Test]
    public function closed_consequential_gate_performs_zero_safe_sync_requests(): void
    {
        $transport = new RecordingConnectorHttpTransport(
            static fn () => throw new \RuntimeException('HTTP must not be called'),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        [$workspace, $account, $variant] = $this->trustedVariant('77');
        $executor = $this->app->make(AdobeProductSimpleCommandExecutor::class);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
            ]),
            adobeBaseCurrency: 'UAH',
            consequentialWriteGate: $this->gate(false),
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('consequential_write_gate_closed', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function invalid_trusted_logical_entity_discriminator_performs_zero_safe_sync_requests(): void
    {
        $transport = new RecordingConnectorHttpTransport(
            static fn () => throw new \RuntimeException('HTTP must not be called'),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        [$workspace, $account, $variant] = $this->trustedVariant('not-an-id');
        $executor = $this->app->make(AdobeProductSimpleCommandExecutor::class);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
            ]),
            adobeBaseCurrency: 'UAH',
            consequentialWriteGate: $this->gate(true),
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('trusted_link_discriminator_invalid', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function trusted_link_sku_mismatch_performs_zero_safe_sync_requests(): void
    {
        $transport = new RecordingConnectorHttpTransport(
            static fn () => throw new \RuntimeException('HTTP must not be called'),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        [$workspace, $account, $variant] = $this->trustedVariant('77', 'OLD-SKU');
        $executor = $this->app->make(AdobeProductSimpleCommandExecutor::class);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
                'sku' => 'NEW-SKU',
            ]),
            adobeBaseCurrency: 'UAH',
            consequentialWriteGate: $this->gate(true),
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('trusted_link_sku_mismatch', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function ambiguous_safe_sync_transport_is_not_retried(): void
    {
        $transport = new RecordingConnectorHttpTransport(
            static fn () => throw new ConnectorTransportException(TransportFailureReason::Timeout),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        [$workspace, $account, $variant] = $this->trustedVariant('77');
        $executor = $this->app->make(AdobeProductSimpleCommandExecutor::class);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
            ]),
            adobeBaseCurrency: 'UAH',
            consequentialWriteGate: $this->gate(true),
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertSame('safe_sync_transport_ambiguous', $result->evidence->reasonCode);
        $this->assertSame(1, $transport->sendCount);
        $this->assertSame(1, $result->evidence->consequentialWriteAttempts);
    }

    #[Test]
    public function ambiguous_safe_sync_bridge_response_is_not_retried(): void
    {
        $transport = new RecordingConnectorHttpTransport(
            static fn () => new ConnectorHttpResult(200, [], '{"applied_state":"broken"}'),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        [$workspace, $account, $variant] = $this->trustedVariant('77');
        $executor = $this->app->make(AdobeProductSimpleCommandExecutor::class);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
            ]),
            adobeBaseCurrency: 'UAH',
            consequentialWriteGate: $this->gate(true),
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertSame('safe_sync_bridge_response_ambiguous', $result->evidence->reasonCode);
        $this->assertSame(1, $transport->sendCount);
        $this->assertSame(1, $result->evidence->consequentialWriteAttempts);
    }

    #[Test]
    public function safe_sync_warning_codes_are_preserved_as_safe_evidence(): void
    {
        $transport = new RecordingConnectorHttpTransport(
            static fn () => new ConnectorHttpResult(200, [], json_encode([
                'applied_state' => 'known_applied',
                'reason_code' => 'safe_sync_known_applied',
                'logical_entity_id' => 77,
                'sku' => 'SKU-TEST-1',
                'postcondition_verified' => true,
                'consequential_write_attempts' => 1,
                'warning_codes' => ['safe_sync_post_commit_callback_failed'],
            ], JSON_THROW_ON_ERROR)),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        [$workspace, $account, $variant] = $this->trustedVariant('77');
        $executor = $this->app->make(AdobeProductSimpleCommandExecutor::class);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
            ]),
            adobeBaseCurrency: 'UAH',
            consequentialWriteGate: $this->gate(true),
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame(['safe_sync_post_commit_callback_failed'], $result->evidence->warningCodes);
    }

    /**
     * @return array{0: Workspace, 1: ConnectorAccount, 2: ProductVariant}
     */
    private function trustedVariant(string $discriminator, string $sku = 'SKU-TEST-1'): array
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variant] = $this->createProductVariant($workspace);

        ExternalRecordLink::query()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $workspace,
                $account->id,
                $variant,
                $sku,
                $discriminator,
            ),
        );

        return [$workspace, $account, $variant];
    }

    /**
     * @return array{0: Product, 1: ProductVariant}
     */
    private function createProductVariant(Workspace $workspace): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-'.Str::random(8),
            'name' => 'Product '.Str::random(4),
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'VAR-'.Str::random(8),
            'is_active' => true,
            'base_price_cache' => 100,
        ]);

        return [$product, $variant];
    }

    private function gate(bool $allowed): SyncLiveConsequentialWriteGate
    {
        return new class($allowed) implements SyncLiveConsequentialWriteGate
        {
            public function __construct(private readonly bool $allowed) {}

            public function permitsConsequentialWrite(): bool
            {
                return $this->allowed;
            }

            public function permitsProductExecution(): bool
            {
                return $this->allowed;
            }
        };
    }
}
