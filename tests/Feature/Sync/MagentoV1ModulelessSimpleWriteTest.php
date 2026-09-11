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
use App\Support\Connectors\AdobePaaS\Command\AdobeProductWriteAccessClassification;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
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

class MagentoV1ModulelessSimpleWriteTest extends TestCase
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
    public function trusted_simple_product_uses_stock_get_put_get_and_verifies_identity(): void
    {
        $remotePrice = 50.0;
        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request) use (&$remotePrice): ConnectorHttpResult {
            $method = $request->request->getMethod();

            if ($method === 'GET') {
                return $this->productResult(77, $remotePrice);
            }

            $payload = json_decode((string) $request->request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $remotePrice = (float) ($payload['product']['price'] ?? -1);

            return new ConnectorHttpResult(200, [], '{}');
        });

        [$workspace, $account, $variant] = $this->trustedVariant('77');
        $result = $this->execute($workspace, $account->id, $variant->id);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame('stock_write_verified', $result->evidence->reasonCode);
        $this->assertSame(1, $result->evidence->consequentialWriteAttempts);
        $this->assertSame(1, $result->evidence->reconciliationGetAttempts);
        $this->assertSame(3, $transport->sendCount);

        $methods = array_map(
            static fn (ConnectorOutboundRequest $request): string => $request->request->getMethod(),
            $transport->recordedRequests,
        );
        $this->assertSame(['GET', 'PUT', 'GET'], $methods);

        foreach ($transport->recordedRequests as $recorded) {
            $this->assertStringNotContainsString('/safe-sync/', (string) $recorded->request->getUri());
        }

        $this->assertSame(
            'https://shop.example.com/rest/default/V1/products/SKU-TEST-1',
            (string) $transport->recordedRequests[1]->request->getUri(),
        );
    }

    #[Test]
    public function identity_mismatch_fails_closed_before_put(): void
    {
        $transport = $this->bindTransport(fn () => $this->productResult(999, 50.0));
        [$workspace, $account, $variant] = $this->trustedVariant('77');

        $result = $this->execute($workspace, $account->id, $variant->id);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('identity_mismatch', $result->evidence->reasonCode);
        $this->assertSame(1, $transport->sendCount);
        $this->assertSame('GET', $transport->recordedRequests[0]->request->getMethod());
    }

    #[Test]
    public function linked_remote_missing_never_falls_back_to_post_create(): void
    {
        $transport = $this->bindTransport(fn () => new ConnectorHttpResult(
            404,
            [],
            AdobeProductCommandTestFixtures::trustedMissing404Body('SKU-TEST-1'),
        ));
        [$workspace, $account, $variant] = $this->trustedVariant('77');

        $result = $this->execute($workspace, $account->id, $variant->id);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('linked_remote_product_missing', $result->evidence->reasonCode);
        $this->assertSame(1, $transport->sendCount);
        $this->assertSame('GET', $transport->recordedRequests[0]->request->getMethod());
    }

    #[Test]
    public function structured_write_permission_denial_is_known_not_applied_and_machine_classified(): void
    {
        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            if ($request->request->getMethod() === 'GET') {
                return $this->productResult(77, 50.0);
            }

            return new ConnectorHttpResult(403, [], json_encode([
                'message' => 'Consumer is not authorized.',
                'parameters' => ['resources' => 'Magento_Catalog::products'],
            ], JSON_THROW_ON_ERROR));
        });
        [$workspace, $account, $variant] = $this->trustedVariant('77');

        $result = $this->execute($workspace, $account->id, $variant->id);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('stock_write_permission_denied', $result->evidence->reasonCode);
        $this->assertSame(AdobeProductWriteAccessClassification::PermissionDenied, $result->evidence->writeAccessClassification);
        $this->assertSame(1, $result->evidence->consequentialWriteAttempts);
        $this->assertSame(0, $result->evidence->reconciliationGetAttempts);
        $this->assertSame(2, $transport->sendCount);
    }

    #[Test]
    public function ambiguous_access_rejection_reconciles_without_second_put(): void
    {
        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            if ($request->request->getMethod() === 'GET') {
                return $this->productResult(77, 50.0);
            }

            return new ConnectorHttpResult(401, [], '{"message":"Access rejected"}');
        });
        [$workspace, $account, $variant] = $this->trustedVariant('77');

        $result = $this->execute($workspace, $account->id, $variant->id);

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertSame('stock_write_http_rejected_or_failed', $result->evidence->reasonCode);
        $this->assertSame(AdobeProductWriteAccessClassification::AccessRejectedUndetermined, $result->evidence->writeAccessClassification);
        $this->assertSame(1, $result->evidence->consequentialWriteAttempts);
        $this->assertSame(1, $result->evidence->reconciliationGetAttempts);
        $this->assertSame(['GET', 'PUT', 'GET'], $this->methods($transport));
    }

    #[Test]
    public function transport_loss_after_put_reconciles_to_known_applied_without_retry(): void
    {
        $remotePrice = 50.0;
        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request) use (&$remotePrice): ConnectorHttpResult {
            if ($request->request->getMethod() === 'GET') {
                return $this->productResult(77, $remotePrice);
            }

            $payload = json_decode((string) $request->request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $remotePrice = (float) ($payload['product']['price'] ?? -1);
            throw new ConnectorTransportException(TransportFailureReason::Timeout);
        });
        [$workspace, $account, $variant] = $this->trustedVariant('77');

        $result = $this->execute($workspace, $account->id, $variant->id);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame('stock_write_verified', $result->evidence->reasonCode);
        $this->assertSame(['GET', 'PUT', 'GET'], $this->methods($transport));
        $this->assertSame(1, $result->evidence->consequentialWriteAttempts);
    }

    #[Test]
    public function successful_put_with_unverified_postcondition_remains_unknown(): void
    {
        $getCount = 0;
        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request) use (&$getCount): ConnectorHttpResult {
            if ($request->request->getMethod() === 'GET') {
                $getCount++;

                return $this->productResult(77, $getCount === 1 ? 50.0 : 75.0);
            }

            return new ConnectorHttpResult(200, [], '{}');
        });
        [$workspace, $account, $variant] = $this->trustedVariant('77');

        $result = $this->execute($workspace, $account->id, $variant->id);

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertSame('stock_write_postcondition_unverified', $result->evidence->reasonCode);
        $this->assertSame(['GET', 'PUT', 'GET'], $this->methods($transport));
    }

    #[Test]
    public function already_matching_remote_state_is_known_applied_with_zero_put(): void
    {
        $transport = $this->bindTransport(fn () => $this->productResult(77, 100.0));
        [$workspace, $account, $variant] = $this->trustedVariant('77');

        $result = $this->execute($workspace, $account->id, $variant->id);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame('stock_state_already_matches', $result->evidence->reasonCode);
        $this->assertSame(0, $result->evidence->consequentialWriteAttempts);
        $this->assertSame(['GET'], $this->methods($transport));
    }

    #[Test]
    public function closed_consequential_gate_performs_zero_http(): void
    {
        $transport = $this->bindTransport(fn () => throw new \RuntimeException('HTTP must not be called'));
        [$workspace, $account, $variant] = $this->trustedVariant('77');

        $result = $this->execute($workspace, $account->id, $variant->id, gateAllowed: false);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('consequential_write_gate_closed', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function write_rejection_body_is_not_persisted_in_safe_command_evidence(): void
    {
        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            if ($request->request->getMethod() === 'GET') {
                return $this->productResult(77, 50.0);
            }

            return new ConnectorHttpResult(403, [], json_encode([
                'message' => 'Authorization SECRET-REMOTE-BODY',
                'parameters' => ['resources' => 'Magento_Catalog::products'],
            ], JSON_THROW_ON_ERROR));
        });
        [$workspace, $account, $variant] = $this->trustedVariant('77');

        $result = $this->execute($workspace, $account->id, $variant->id);
        $encoded = json_encode($result->evidence, JSON_THROW_ON_ERROR);

        $this->assertSame(AdobeProductWriteAccessClassification::PermissionDenied, $result->evidence->writeAccessClassification);
        $this->assertStringNotContainsString('SECRET-REMOTE-BODY', $encoded);
        $this->assertStringNotContainsString('Authorization', $encoded);
        $this->assertSame(['GET', 'PUT'], $this->methods($transport));
    }

    private function bindTransport(\Closure $responder): RecordingConnectorHttpTransport
    {
        $transport = new RecordingConnectorHttpTransport($responder);
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        return $transport;
    }

    private function productResult(int $entityId, float $price): ConnectorHttpResult
    {
        return new ConnectorHttpResult(200, [], json_encode(
            AdobeProductCommandTestFixtures::remoteProductPayload([
                'id' => $entityId,
                'price' => $price,
            ]),
            JSON_THROW_ON_ERROR,
        ));
    }

    private function execute(
        Workspace $workspace,
        string $accountId,
        string $variantId,
        bool $gateAllowed = true,
    ) {
        return app(AdobeProductSimpleCommandExecutor::class)->execute(
            new AdobeProductSimpleCommandInput(
                workspaceId: $workspace->id,
                connectorAccountId: $accountId,
                semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                    'variant_id' => $variantId,
                ]),
                adobeBaseCurrency: 'UAH',
                consequentialWriteGate: $this->gate($gateAllowed),
            ),
        );
    }

    /**
     * @return array{0: Workspace, 1: ConnectorAccount, 2: ProductVariant}
     */
    private function trustedVariant(string $discriminator): array
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variant] = $this->createProductVariant($workspace);
        ExternalRecordLink::query()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $workspace,
                $account->id,
                $variant,
                'SKU-TEST-1',
                $discriminator,
                $this->createWorkspaceActor($workspace),
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
            'sku' => 'SKU-TEST-1',
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

    /**
     * @return list<string>
     */
    private function methods(RecordingConnectorHttpTransport $transport): array
    {
        return array_map(
            static fn (ConnectorOutboundRequest $request): string => $request->request->getMethod(),
            $transport->recordedRequests,
        );
    }
}
