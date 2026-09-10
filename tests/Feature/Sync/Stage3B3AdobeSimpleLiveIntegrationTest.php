<?php

namespace Tests\Feature\Sync;

use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncLiveOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Jobs\Connectors\SyncLiveRunJob;
use App\Jobs\Connectors\SyncLiveRunJobExecutionException;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\CreateSyncConfigurationInput;
use App\Services\Sync\FieldMappingMutationService;
use App\Services\Sync\SyncConfigurationService;
use App\Services\Sync\SyncLiveAdmissionService;
use App\Services\Sync\SyncPreviewConfigurationSnapshotBuilder;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductCommandRequestFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkGuard;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteGetClassifier;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateClient;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateNormalizer;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandInput;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncClient;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncRequestFactory;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\SyncLiveAdmissionException;
use App\Support\Sync\Live\SyncLiveConnectorCapabilityResolver;
use App\Support\Sync\Live\SyncLiveConsequentialWriteGate;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use App\Support\Sync\SyncExternalContext;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeProductCommandTestFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class Stage3B3AdobeSimpleLiveIntegrationTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    /** @var array<string, float> */
    private array $remoteSkuPrices = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();
    }

    #[Test]
    public function production_live_admission_rejects_adobe_while_support_is_false(): void
    {
        $account = $this->createConnectorAccount();
        $configuration = $this->prepareMappedConfiguration($account);
        $actor = $this->grantLivePermission($account->workspace);
        $this->seedCompletedPreview($account, $configuration);

        $this->expectException(SyncLiveAdmissionException::class);

        app(SyncLiveAdmissionService::class)->admit($actor, $account, $configuration->id);
    }

    #[Test]
    public function live_job_completes_through_generic_capability_with_adobe_binding(): void
    {
        $transport = $this->bindAdobeTransport();
        $account = $this->createConnectorAccount();
        $configuration = $this->prepareMappedConfiguration($account);
        [$product, $variant] = $this->createPricedProductVariant($account->workspace, 'LIVE-SKU-1', 150.0);

        $run = $this->queuedLiveRun($account, $configuration, $this->fullSnapshot($configuration));

        (new SyncLiveRunJob($account->workspace_id, $account->id, $run->id))->handle(
            app(ProductExecutionAggregateBuilder::class),
            app(SyncLiveConnectorCapabilityResolver::class),
        );

        $run = $run->fresh();
        $this->assertSame(SyncRunStatus::Completed, $run->status);
        $this->assertSame(1, SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->count());

        $item = SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->sole();
        $this->assertSame($product->id, $item->product_id);
        $this->assertGreaterThan(0, $transport->sendCount);
        $uris = array_map(
            static fn (ConnectorOutboundRequest $request): string => (string) $request->request->getUri(),
            $transport->recordedRequests,
        );
        $this->assertTrue(collect($uris)->contains(
            static fn (string $uri): bool => str_contains($uri, '/store/storeConfigs'),
        ));
    }

    #[Test]
    public function workspace_configuration_mismatch_fails_before_http(): void
    {
        $transport = $this->bindAdobeTransport();
        $account = $this->createConnectorAccount();
        $otherAccount = $this->createConnectorAccount($account->workspace);
        $configuration = $this->prepareMappedConfiguration($account);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Live,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Queued,
            'configuration_snapshot' => $this->fullSnapshot($configuration),
        ]);

        try {
            (new SyncLiveRunJob($account->workspace_id, $otherAccount->id, $run->id))->handle(
                app(ProductExecutionAggregateBuilder::class),
                app(SyncLiveConnectorCapabilityResolver::class),
            );
            $this->fail('Expected configuration mismatch failure.');
        } catch (SyncLiveRunJobExecutionException) {
            // expected
        }

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(SyncRunStatus::Failed, $run->fresh()->status);
    }

    #[Test]
    public function unsupported_selection_mode_fails_before_http(): void
    {
        $transport = $this->bindAdobeTransport();
        $account = $this->createConnectorAccount();
        $configuration = $this->prepareMappedConfiguration($account);
        $this->createPricedProductVariant($account->workspace, 'LIVE-SKU-2', 100.0);

        $snapshot = $this->fullSnapshot($configuration);
        $snapshot['selection']['mode'] = 'subset';

        $run = $this->queuedLiveRun($account, $configuration, $snapshot);

        try {
            (new SyncLiveRunJob($account->workspace_id, $account->id, $run->id))->handle(
                app(ProductExecutionAggregateBuilder::class),
                app(SyncLiveConnectorCapabilityResolver::class),
            );
            $this->fail('Expected unsupported selection failure.');
        } catch (SyncLiveRunJobExecutionException) {
            // expected
        }

        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function store_config_failure_causes_zero_product_writes(): void
    {
        $transport = $this->bindAdobeTransport(responder: function (ConnectorOutboundRequest $request, int $count): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/store/storeConfigs')) {
                return new ConnectorHttpResult(403, [], '{"message":"Forbidden"}');
            }

            return $this->defaultAdobeResponder($request);
        });

        $account = $this->createConnectorAccount();
        $configuration = $this->prepareMappedConfiguration($account);
        $this->createPricedProductVariant($account->workspace, 'LIVE-SKU-3', 100.0);

        $run = $this->queuedLiveRun($account, $configuration, $this->fullSnapshot($configuration));

        try {
            (new SyncLiveRunJob($account->workspace_id, $account->id, $run->id))->handle(
                app(ProductExecutionAggregateBuilder::class),
                app(SyncLiveConnectorCapabilityResolver::class),
            );
            $this->fail('Expected store config preparation failure.');
        } catch (SyncLiveRunJobExecutionException) {
            // expected
        }

        $this->assertGreaterThan(0, $transport->sendCount);
        $uris = array_map(
            static fn (ConnectorOutboundRequest $request): string => (string) $request->request->getUri(),
            $transport->recordedRequests,
        );
        $this->assertTrue(collect($uris)->contains(
            static fn (string $uri): bool => str_contains($uri, '/store/storeConfigs'),
        ));
        $productConsequentialRequests = array_filter(
            $transport->recordedRequests,
            static function (ConnectorOutboundRequest $request): bool {
                $uri = (string) $request->request->getUri();
                $method = $request->request->getMethod();

                if (str_contains($uri, '/attribute-sets/')) {
                    return false;
                }

                return in_array($method, ['POST', 'PUT'], true)
                    && str_contains($uri, '/V1/products');
            },
        );
        $this->assertSame(0, count($productConsequentialRequests));
        $this->assertSame(0, SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->count());
    }

    #[Test]
    public function live_rebuilds_fresh_product_state_without_consequential_writes_until_write_bridge(): void
    {
        $transport = $this->bindAdobeTransport();
        $account = $this->createConnectorAccount();
        $configuration = $this->prepareMappedConfiguration($account);
        [$product, $variant] = $this->createPricedProductVariant($account->workspace, 'FRESH-SKU', 100.0);

        SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'configuration_snapshot' => $this->fullSnapshot($configuration),
            'completed_at' => now(),
        ]);

        ExternalRecordLink::query()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'FRESH-SKU',
        ]);

        $this->remoteSkuPrices['FRESH-SKU'] = 100.0;

        $variant->update(['base_price_cache' => 250.0]);
        PriceListItem::query()
            ->where('product_variant_id', $variant->id)
            ->update(['price' => 250.0]);

        $run = $this->queuedLiveRun($account, $configuration, $this->fullSnapshot($configuration));

        (new SyncLiveRunJob($account->workspace_id, $account->id, $run->id))->handle(
            app(ProductExecutionAggregateBuilder::class),
            app(SyncLiveConnectorCapabilityResolver::class),
        );

        $putRequests = array_filter(
            $transport->recordedRequests,
            static fn (ConnectorOutboundRequest $request): bool => $request->request->getMethod() === 'PUT',
        );

        $this->assertEmpty($putRequests);

        $item = SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->sole();
        $this->assertSame(SyncLiveOutcome::NotApplied, $item->liveOutcome());
    }

    #[Test]
    public function configurable_product_is_not_applied_with_zero_writes(): void
    {
        $transport = $this->bindAdobeTransport();
        $account = $this->createConnectorAccount();
        $configuration = $this->prepareMappedConfiguration($account);
        $workspace = $account->workspace;

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-PARENT',
            'name' => 'Configurable Parent',
            'is_active' => true,
        ]);

        foreach (['RED', 'BLUE'] as $index => $color) {
            $variant = ProductVariant::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'product_id' => $product->id,
                'onec_guid' => (string) Str::uuid(),
                'sku' => 'CFG-'.$color,
                'is_active' => true,
                'base_price_cache' => 100 + $index,
            ]);
            $this->attachVariantPrice($workspace, $variant, 100 + $index);
        }

        $run = $this->queuedLiveRun($account, $configuration, $this->fullSnapshot($configuration));

        (new SyncLiveRunJob($account->workspace_id, $account->id, $run->id))->handle(
            app(ProductExecutionAggregateBuilder::class),
            app(SyncLiveConnectorCapabilityResolver::class),
        );

        $item = SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->sole();
        $this->assertSame(SyncLiveOutcome::NotApplied, $item->liveOutcome());

        $productWriteCount = count(array_filter(
            $transport->recordedRequests,
            static fn (ConnectorOutboundRequest $request): bool => in_array(
                $request->request->getMethod(),
                ['POST', 'PUT'],
                true,
            ),
        ));
        $this->assertSame(0, $productWriteCount);
    }

    #[Test]
    public function legacy_link_fails_closed_before_get_with_zero_consequential_writes(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createPricedProductVariant($workspace, 'SKU-TEST-1', 100.0);

        [$executor, $transport] = $this->executor(responder: fn (ConnectorOutboundRequest $request): ConnectorHttpResult => new ConnectorHttpResult(
            200,
            [],
            json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(['price' => 50.0]), JSON_THROW_ON_ERROR),
        ));

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'SKU-TEST-1',
        ]);

        $gate = new class implements SyncLiveConsequentialWriteGate
        {
            public function permitsConsequentialWrite(): bool
            {
                return false;
            }

            public function permitsProductExecution(): bool
            {
                return true;
            }
        };

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
            adobeBaseCurrency: 'UAH',
            consequentialWriteGate: $gate,
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function completed_product_items_persist_when_later_infrastructure_error_terminalizes_run(): void
    {
        $account = $this->createConnectorAccount();
        $configuration = $this->prepareMappedConfiguration($account);
        [$product] = $this->createPricedProductVariant($account->workspace, 'SAFE-SKU', 100.0);

        $run = $this->queuedLiveRun($account, $configuration, $this->fullSnapshot($configuration));

        SyncRunItem::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_run_id' => $run->id,
            'product_id' => $product->id,
            'outcome' => SyncLiveOutcome::Synchronized->value,
            'findings' => [],
        ]);

        try {
            (new SyncLiveRunJob($account->workspace_id, $account->id, $run->id))->handle(
                app(ProductExecutionAggregateBuilder::class),
                app(SyncLiveConnectorCapabilityResolver::class),
            );
            $this->fail('Expected infrastructure failure.');
        } catch (SyncLiveRunJobExecutionException) {
            // expected when prepareRun fails without transport binding
        }

        $this->assertSame(1, SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->count());
    }

    #[Test]
    public function sync_run_item_findings_exclude_raw_http_and_credentials(): void
    {
        $transport = $this->bindAdobeTransport();
        $account = $this->createConnectorAccount();
        $configuration = $this->prepareMappedConfiguration($account);
        $this->createPricedProductVariant($account->workspace, 'SAFE-SKU', 100.0);

        $run = $this->queuedLiveRun($account, $configuration, $this->fullSnapshot($configuration));

        (new SyncLiveRunJob($account->workspace_id, $account->id, $run->id))->handle(
            app(ProductExecutionAggregateBuilder::class),
            app(SyncLiveConnectorCapabilityResolver::class),
        );

        $encoded = json_encode(SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->sole()->findings, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Authorization', $encoded);
        $this->assertStringNotContainsString('cs_live', $encoded);
        $this->assertStringNotContainsString('oauth', strtolower($encoded));
    }

    #[Test]
    public function live_job_tries_remain_single(): void
    {
        $job = new SyncLiveRunJob('ws', 'acc', 'run');
        $this->assertSame(1, $job->tries);
    }

    private function bindAdobeTransport(?\Closure $responder = null): RecordingConnectorHttpTransport
    {
        $this->remoteSkuPrices = [];

        $transport = new RecordingConnectorHttpTransport(
            $responder ?? fn (ConnectorOutboundRequest $request, int $count): ConnectorHttpResult => $this->defaultAdobeResponder($request),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        return $transport;
    }

    private function defaultAdobeResponder(ConnectorOutboundRequest $request): ConnectorHttpResult
    {
        $uri = (string) $request->request->getUri();
        $method = $request->request->getMethod();

        if (str_contains($uri, '/store/storeConfigs')) {
            return new ConnectorHttpResult(200, [], json_encode([
                ['code' => 'default', 'base_currency_code' => 'UAH'],
            ], JSON_THROW_ON_ERROR));
        }

        if (str_contains($uri, '/attribute-sets/sets/list')) {
            return new ConnectorHttpResult(200, [], json_encode([
                'items' => [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            ], JSON_THROW_ON_ERROR));
        }

        if (str_contains($uri, '/attribute-sets/4/attributes')) {
            return new ConnectorHttpResult(200, [], json_encode([
                ['attribute_id' => 1, 'attribute_code' => 'name', 'frontend_input' => 'text', 'scope' => 'global'],
                ['attribute_id' => 2, 'attribute_code' => 'sku', 'frontend_input' => 'text', 'scope' => 'global'],
                ['attribute_id' => 3, 'attribute_code' => 'status', 'frontend_input' => 'select', 'scope' => 'global', 'options' => [['value' => '1', 'label' => 'Enabled']]],
            ], JSON_THROW_ON_ERROR));
        }

        if ($method === 'GET' && str_contains($uri, '/V1/products/')) {
            $sku = $this->skuFromProductUri($uri);
            $remotePrice = $this->remoteSkuPrices[$sku] ?? null;

            if ($remotePrice !== null) {
                return new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(AdobeProductCommandTestFixtures::remoteProductPayload([
                        'sku' => $sku,
                        'price' => $remotePrice,
                    ]), JSON_THROW_ON_ERROR),
                );
            }

            return new ConnectorHttpResult(
                404,
                [],
                AdobeProductCommandTestFixtures::trustedMissing404Body($sku),
            );
        }

        if ($method === 'PUT' && str_contains($uri, '/V1/products/')) {
            $sku = $this->skuFromProductUri($uri);
            $payload = json_decode((string) $request->request->getBody(), true);
            $product = is_array($payload) ? ($payload['product'] ?? $payload) : [];
            $price = is_array($product) ? ($product['price'] ?? null) : null;

            if (is_string($sku) && is_numeric($price)) {
                $this->remoteSkuPrices[$sku] = (float) $price;
            }

            return new ConnectorHttpResult(200, [], '{}');
        }

        if ($method === 'POST' && str_contains($uri, '/V1/products')) {
            return new ConnectorHttpResult(200, [], json_encode(['sku' => $this->skuFromPostBody($request)], JSON_THROW_ON_ERROR));
        }

        return new ConnectorHttpResult(404, [], '{}');
    }

    private function skuFromProductUri(string $uri): string
    {
        if (preg_match('/\/V1\/products\/([^?]+)/', $uri, $matches) !== 1) {
            return 'UNKNOWN';
        }

        return rawurldecode($matches[1]);
    }

    private function skuFromPostBody(ConnectorOutboundRequest $request): string
    {
        $payload = json_decode((string) $request->request->getBody(), true);

        return is_array($payload) ? (string) ($payload['product']['sku'] ?? 'UNKNOWN') : 'UNKNOWN';
    }

    /**
     * @return array{0: AdobeProductSimpleCommandExecutor, 1: RecordingConnectorHttpTransport}
     */
    private function executor(?\Closure $responder = null): array
    {
        $transport = new RecordingConnectorHttpTransport(
            $responder ?? fn (): ConnectorHttpResult => new ConnectorHttpResult(500, [], '{}'),
        );

        $normalizer = new AdobeProductRemoteStateNormalizer;
        $classifier = new AdobeProductRemoteGetClassifier($normalizer);
        $client = new AdobeProductRemoteStateClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
            $transport,
            $classifier,
        );

        $linkGuard = new AdobeProductExternalRecordLinkGuard;

        $executor = new AdobeProductSimpleCommandExecutor(
            new AdobeProductDesiredStateCompiler,
            $linkGuard,
            new AdobeSafeSyncClient(
                app(AdobePaaSRequestContextFactory::class),
                new AdobeSafeSyncRequestFactory(new OAuth1RequestSigner),
                $transport,
            ),
        );

        return [$executor, $transport];
    }

    private function prepareMappedConfiguration(ConnectorAccount $account): SyncConfiguration
    {
        $configuration = app(SyncConfigurationService::class)->create($account, new CreateSyncConfigurationInput(
            dataDomain: SyncDataDomain::Products,
            externalContext: SyncExternalContext::default(),
            enabledOperations: [SyncSemanticOperation::Export],
            operationalState: SyncConfigurationOperationalState::Enabled,
        ));

        $configuration = app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => 4]),
        );

        $this->publishAuthoritativeSnapshot($account, ['name', 'sku', 'status']);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productBinding('name')->id,
            'name',
        );

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productVariantBinding('sku')->id,
            'sku',
        );

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productBinding('status')->id,
            'status',
        );

        return $configuration->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function fullSnapshot(SyncConfiguration $configuration): array
    {
        return app(SyncPreviewConfigurationSnapshotBuilder::class)
            ->build($configuration, SyncSemanticOperation::Export);
    }

    private function queuedLiveRun(
        ConnectorAccount $account,
        SyncConfiguration $configuration,
        array $snapshot,
    ): SyncRun {
        return SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Live,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Queued,
            'configuration_snapshot' => $snapshot,
        ]);
    }

    private function seedCompletedPreview(ConnectorAccount $account, SyncConfiguration $configuration): void
    {
        SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'configuration_snapshot' => $this->fullSnapshot($configuration),
            'completed_at' => now(),
        ]);
    }

    private function grantLivePermission(Workspace $workspace): User
    {
        $actor = $this->createStaffUser(UserRole::Manager);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Live Runner',
            [WorkspacePermissions::RUN_SYNC_LIVE],
        );
        $this->assignRoleToMembership($membership, $role);

        return $actor;
    }

    /**
     * @return array{0: Product, 1: ProductVariant}
     */
    private function createPricedProductVariant(Workspace $workspace, string $sku, float $price): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'is_active' => true,
            'base_price_cache' => $price,
        ]);

        $this->attachVariantPrice($workspace, $variant, $price);

        return [$product, $variant];
    }

    private function attachVariantPrice(Workspace $workspace, ProductVariant $variant, float $price): void
    {
        $priceList = PriceList::withoutWorkspaceScope()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'is_default' => true,
            ],
            [
                'name' => 'Workspace Default',
                'currency' => 'UAH',
                'priority' => 0,
                'status' => PriceListStatus::Active,
            ],
        );

        PriceListItem::withoutWorkspaceScope()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'price_list_id' => $priceList->id,
                'product_variant_id' => $variant->id,
                'quantity_min' => 1,
            ],
            [
                'price' => $price,
                'status' => PriceListItemStatus::Active,
            ],
        );
    }
}
