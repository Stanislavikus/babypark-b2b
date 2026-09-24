<?php

namespace Tests\Feature\Sync;

use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Models\AdobeProductAttributeSet;
use App\Models\ConnectorSchemaSource;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductModulelessSimpleCreateExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandInput;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeProductCommandTestFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\Support\Sync\SyncLiveConsequentialWriteGateStub;
use Tests\TestCase;

final class MagentoModulelessSimpleCreateTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function trusted_missing_then_standard_post_and_exact_read_back_creates_platform_link(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [, $variant] = $this->createProductVariant($workspace, 'MODULELESS-CREATE-1');
        $this->createAttributeSet($account->workspace_id, $account->id, $account->connector_definition_id, 4);

        $transport = new RecordingConnectorHttpTransport(function ($request, int $count) use ($variant): ConnectorHttpResult {
            return match ($count) {
                1 => new ConnectorHttpResult(
                    404,
                    [],
                    AdobeProductCommandTestFixtures::trustedMissing404Body($variant->sku),
                ),
                2 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(
                        AdobeProductCommandTestFixtures::remoteProductPayload([
                            'id' => 701,
                            'sku' => $variant->sku,
                        ]),
                        JSON_THROW_ON_ERROR,
                    ),
                ),
                3, 4 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(
                        AdobeProductCommandTestFixtures::remoteProductPayload([
                            'id' => 701,
                            'sku' => $variant->sku,
                        ]),
                        JSON_THROW_ON_ERROR,
                    ),
                ),
                default => throw new \RuntimeException('Unexpected request.'),
            };
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeProductModulelessSimpleCreateExecutor::class)->execute(
            $this->input($workspace, $account->id, $variant),
        );

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame('adobe_create_post_reconciled', $result->evidence->reasonCode);
        $this->assertSame(1, $result->evidence->consequentialWriteAttempts);
        $this->assertSame(1, $result->evidence->reconciliationGetAttempts);
        $this->assertTrue($result->evidence->externalRecordLinkPersisted);
        $this->assertTrue($result->evidence->ownershipTrustSatisfied);

        $link = ExternalRecordLink::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('connector_account_id', $account->id)
            ->where('product_variant_id', $variant->id)
            ->sole();

        $this->assertSame(ExternalRecordLinkTrustOrigin::PlatformCreated->value, $link->trust_origin);
        $this->assertSame($variant->sku, $link->external_identifier);
        $this->assertSame('701', $link->external_record_discriminator);
        $this->assertTrue($link->hasTrustedIdentity());

        $updated = app(AdobeProductSimpleCommandExecutor::class)->execute(
            $this->input($workspace, $account->id, $variant),
        );

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $updated->appliedStateKnowledge);
        $this->assertSame('stock_state_already_matches', $updated->evidence->reasonCode);
        $this->assertTrue($updated->evidence->ownershipTrustSatisfied);

        $this->assertSame(['GET', 'POST', 'GET', 'GET'], array_map(
            static fn ($request): string => $request->request->getMethod(),
            $transport->recordedRequests,
        ));
        $this->assertStringContainsString('/V1/products', (string) $transport->recordedRequests[1]->request->getUri());
    }

    #[Test]
    public function existing_remote_sku_without_trusted_link_performs_zero_post(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [, $variant] = $this->createProductVariant($workspace, 'MODULELESS-EXISTS-1');
        $this->createAttributeSet($account->workspace_id, $account->id, $account->connector_definition_id, 4);

        $transport = new RecordingConnectorHttpTransport(fn (): ConnectorHttpResult => new ConnectorHttpResult(
            200,
            [],
            json_encode(
                AdobeProductCommandTestFixtures::remoteProductPayload([
                    'id' => 702,
                    'sku' => $variant->sku,
                ]),
                JSON_THROW_ON_ERROR,
            ),
        ));
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeProductModulelessSimpleCreateExecutor::class)->execute(
            $this->input($workspace, $account->id, $variant),
        );

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('remote_found_without_trusted_link', $result->evidence->reasonCode);
        $this->assertSame(1, $transport->sendCount);
        $this->assertSame('GET', $transport->recordedRequests[0]->request->getMethod());
        $this->assertDatabaseCount('external_record_links', 0);
    }

    #[Test]
    public function ambiguous_post_is_never_retried_and_never_mints_platform_trust(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [, $variant] = $this->createProductVariant($workspace, 'MODULELESS-TIMEOUT-1');
        $this->createAttributeSet($account->workspace_id, $account->id, $account->connector_definition_id, 4);

        $transport = new RecordingConnectorHttpTransport(function ($request, int $count) use ($variant): ConnectorHttpResult {
            if ($count === 1) {
                return new ConnectorHttpResult(
                    404,
                    [],
                    AdobeProductCommandTestFixtures::trustedMissing404Body($variant->sku),
                );
            }

            if ($count === 2) {
                throw new ConnectorTransportException(TransportFailureReason::Timeout);
            }

            if ($count === 3) {
                return new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(
                        AdobeProductCommandTestFixtures::remoteProductPayload([
                            'id' => 703,
                            'sku' => $variant->sku,
                        ]),
                        JSON_THROW_ON_ERROR,
                    ),
                );
            }

            throw new \RuntimeException('Unexpected request.');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeProductModulelessSimpleCreateExecutor::class)->execute(
            $this->input($workspace, $account->id, $variant),
        );

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertSame('adobe_create_post_ambiguous_remote_present', $result->evidence->reasonCode);
        $this->assertSame(1, $result->evidence->consequentialWriteAttempts);
        $this->assertSame(1, $result->evidence->reconciliationGetAttempts);
        $this->assertFalse($result->evidence->externalRecordLinkPersisted);
        $this->assertSame(['GET', 'POST', 'GET'], array_map(
            static fn ($request): string => $request->request->getMethod(),
            $transport->recordedRequests,
        ));
        $this->assertDatabaseCount('external_record_links', 0);
    }

    private function input(Workspace $workspace, string $accountId, ProductVariant $variant): AdobeProductSimpleCommandInput
    {
        return new AdobeProductSimpleCommandInput(
            workspaceId: (string) $workspace->id,
            connectorAccountId: $accountId,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'product_id' => (string) $variant->product_id,
                'variant_id' => (string) $variant->id,
                'sku' => $variant->sku,
                'attribute_set_id' => 4,
            ]),
            adobeBaseCurrency: 'UAH',
            consequentialWriteGate: new SyncLiveConsequentialWriteGateStub(true),
        );
    }

    private function createAttributeSet(
        string $workspaceId,
        string $connectorAccountId,
        string $connectorDefinitionId,
        int $providerId,
    ): void {
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $connectorDefinitionId)
            ->where('code', 'live_account_attributes')
            ->firstOrFail();

        AdobeProductAttributeSet::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'connector_schema_source_id' => $source->id,
            'provider_attribute_set_id' => $providerId,
            'name' => 'Moduleless Create Set',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_since' => null,
        ]);
    }

    /** @return array{0: Product, 1: ProductVariant} */
    private function createProductVariant(Workspace $workspace, string $sku): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'PARENT-'.$sku,
            'name' => 'Moduleless Product',
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'is_active' => true,
            'base_price_cache' => 100,
        ]);

        return [$product, $variant];
    }
}
