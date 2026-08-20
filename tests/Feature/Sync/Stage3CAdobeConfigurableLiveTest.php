<?php

namespace Tests\Feature\Sync;

use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Enums\SyncLiveOutcome;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use App\Models\Workspace;
use App\Support\Connectors\AdobePaaS\AdobeAttributeMetadata;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
use App\Support\Connectors\AdobePaaS\AdobeProductExportLiveCapability;
use App\Support\Connectors\AdobePaaS\AdobeProductExportLiveRunContext;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableAppliedStateAggregator;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableChildLinkCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableCommandEvidence;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableCommandInput;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableInactiveLinkedVariantLifecycleExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableOptionCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableParentCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableParentSkuGenerator;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableProductCommandCoordinator;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableRemoteOptionStateReader;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductCommandRequestFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkGuard;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersister;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductObservedState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductOwnershipTrustPolicy;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductParentDesiredState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductParentObservedState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteGetClassifier;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateClient;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateComparator;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateNormalizer;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\ConservativeAdobeProductOwnershipTrustPolicy;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeConfigurableCommandTestFixtures;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeProductCommandTestFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\Support\Sync\SyncLiveConsequentialWriteGateStub;
use Tests\TestCase;

class Stage3CAdobeConfigurableLiveTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seedFieldDefinitions();
    }

    #[Test]
    public function aggregator_maps_all_applied_to_synchronized(): void
    {
        $aggregator = new AdobeConfigurableAppliedStateAggregator;

        $outcome = $aggregator->aggregate([
            new AdobeConfigurableCommandEvidence('simple_child', AdobeProductAppliedStateKnowledge::KnownApplied, 'ok'),
            new AdobeConfigurableCommandEvidence('configurable_parent', AdobeProductAppliedStateKnowledge::KnownApplied, 'ok'),
        ]);

        $this->assertSame(SyncLiveOutcome::Synchronized, $outcome);
    }

    #[Test]
    public function aggregator_maps_mixed_applied_and_not_applied_to_partial(): void
    {
        $aggregator = new AdobeConfigurableAppliedStateAggregator;

        $outcome = $aggregator->aggregate([
            new AdobeConfigurableCommandEvidence('simple_child', AdobeProductAppliedStateKnowledge::KnownApplied, 'ok'),
            new AdobeConfigurableCommandEvidence('configurable_parent', AdobeProductAppliedStateKnowledge::KnownNotApplied, 'rejected'),
        ]);

        $this->assertSame(SyncLiveOutcome::Partial, $outcome);
    }

    #[Test]
    public function aggregator_maps_any_unknown_to_ambiguous_outranking_partial(): void
    {
        $aggregator = new AdobeConfigurableAppliedStateAggregator;

        $outcome = $aggregator->aggregate([
            new AdobeConfigurableCommandEvidence('simple_child', AdobeProductAppliedStateKnowledge::KnownApplied, 'ok'),
            new AdobeConfigurableCommandEvidence('child_link', AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, 'uncertain'),
            new AdobeConfigurableCommandEvidence('configurable_parent', AdobeProductAppliedStateKnowledge::KnownNotApplied, 'rejected'),
        ]);

        $this->assertSame(SyncLiveOutcome::Ambiguous, $outcome);
    }

    #[Test]
    public function child_compiler_includes_resolved_configurable_values_as_custom_attributes(): void
    {
        $compiler = new AdobeProductDesiredStateCompiler;
        $semantic = AdobeConfigurableCommandTestFixtures::configurableSemanticResult();

        $desired = $compiler->compileSimpleChildFromSemanticResult($semantic, '10');

        $this->assertArrayHasKey('color', $desired->customAttributes);
        $this->assertSame(93, $desired->customAttributes['color']);
    }

    #[Test]
    public function parent_payload_builder_omits_price(): void
    {
        $factory = new AdobeProductCommandRequestFactory(new OAuth1RequestSigner);
        $parent = new AdobeProductParentDesiredState(
            productId: 1,
            sku: 'cfg-test',
            name: 'Parent',
            attributeSetId: 4,
            typeId: 'configurable',
            status: 1,
            visibility: 4,
            customAttributes: [],
        );

        $method = new \ReflectionMethod($factory, 'encodeParentProductEnvelope');
        $method->setAccessible(true);
        $encoded = $method->invoke($factory, $parent);
        $payload = json_decode($encoded, true);

        $this->assertArrayNotHasKey('price', $payload['product']);
    }

    #[Test]
    public function generated_parent_collision_with_product_erl_is_zero_post(): void
    {
        [$parentExecutor, $transport] = $this->parentExecutorStack();
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$otherProduct] = $this->createConfigurableProduct($workspace, 'CFG-OTHER');
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-TARGET');

        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $otherProduct->id,
            'external_identifier' => $parentSku,
        ]);

        $input = $this->configurableInput($workspace, $account, $product);

        $result = $parentExecutor->execute($input);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('external_record_link_collision', $result->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function trusted_parent_identity_drift_is_ambiguous_with_zero_write(): void
    {
        [$parentExecutor, $transport] = $this->parentExecutorStack();
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-TARGET');
        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'stale-parent-sku',
        ]);

        $result = $parentExecutor->execute($this->configurableInput($workspace, $account, $product));

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertSame('linked_parent_identity_drift_requires_adobe_validation', $result->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function production_conservative_parent_create_is_ambiguous_without_erl(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-CONSERVATIVE');
        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);

        [$parentExecutor, $transport] = $this->parentExecutorStack(responder: function () use ($parentSku) {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(404, [], AdobeProductCommandTestFixtures::trustedMissing404Body($parentSku)),
                2 => new ConnectorHttpResult(200, [], json_encode(['product' => AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku)], JSON_THROW_ON_ERROR)),
                3 => new ConnectorHttpResult(200, [], json_encode(AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku), JSON_THROW_ON_ERROR)),
                default => new ConnectorHttpResult(500, [], '{}'),
            };
        });

        $result = $parentExecutor->execute($this->configurableInput($workspace, $account, $product));

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertStringContainsString('ownership_not_proven', $result->reasonCode);
        $this->assertSame(0, ExternalRecordLink::query()->where('product_id', $product->id)->count());
        $this->assertGreaterThan(0, $transport->sendCount);
    }

    #[Test]
    public function coordinator_stops_all_writes_when_child_is_unknown(): void
    {
        [$coordinator, $transport] = $this->coordinatorStack(responder: function () {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(404, [], AdobeProductCommandTestFixtures::trustedMissing404Body('CHILD-BLUE')),
                2 => new ConnectorHttpResult(500, [], '{}'),
                default => new ConnectorHttpResult(500, [], '{}'),
            };
        });

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-TARGET');

        $result = $coordinator->execute(
            $workspace->id,
            $account->id,
            AdobeConfigurableCommandTestFixtures::configurableSemanticResult($product->id),
            'UAH',
            $this->metadataFixture(),
            null,
        );

        $this->assertSame(SyncLiveOutcome::Ambiguous, $result->outcome);
        $this->assertFalse(collect($transport->recordedRequests)->contains(
            fn ($request) => str_contains((string) $request->request->getUri(), '/configurable-products/'),
        ));
    }

    #[Test]
    public function classification_transition_simple_semantic_with_trusted_parent_is_not_applied(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variant] = $this->createSimpleProductWithVariant($workspace);

        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);
        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => $parentSku,
        ]);

        $capability = app(AdobeProductExportLiveCapability::class);
        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $this->simpleSnapshot(),
        )[0];

        $runContext = (new AdobeProductExportLiveRunContext(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            metadata: $this->metadataFixture(),
            adobeBaseCurrency: 'UAH',
        ));

        $result = $capability->executeProduct(
            $aggregate,
            $this->simpleSnapshot(),
            $runContext,
            new SyncLiveConsequentialWriteGateStub(true),
        );

        $this->assertSame(SyncLiveOutcome::NotApplied, $result->outcome);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === 'configurable_classification_transition_requires_adobe_validation',
        ));
    }

    #[Test]
    public function trusted_preseeded_configurable_happy_path_reaches_synchronized_with_test_ownership_override(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-HAPPY');
        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => $parentSku,
        ]);

        foreach ($variants as $variant) {
            ExternalRecordLink::query()->create([
                'workspace_id' => $workspace->id,
                'connector_account_id' => $account->id,
                'product_variant_id' => $variant->id,
                'external_identifier' => $variant->sku,
            ]);
        }

        [$coordinator, $transport] = $this->coordinatorStack(
            ownershipPolicy: new class implements AdobeProductOwnershipTrustPolicy
            {
                public function canPersistNewLink(
                    AdobeProductDesiredState $desiredState,
                    AdobeProductObservedState $observedState,
                ): bool {
                    return true;
                }

                public function canPersistNewParentLink(
                    AdobeProductParentDesiredState $desiredState,
                    AdobeProductParentObservedState $observedState,
                ): bool {
                    return true;
                }
            },
            responder: function () use ($variants, $parentSku): ConnectorHttpResult {
                static $count = 0;
                $count++;

                $blueSku = $variants[0]->sku;
                $redSku = $variants[1]->sku;

                return match ($count) {
                    1 => new ConnectorHttpResult(200, [], json_encode(AdobeProductCommandTestFixtures::remoteProductPayload([
                        'sku' => $blueSku,
                        'name' => 'Configurable Product',
                        'visibility' => 1,
                        'custom_attributes' => [['attribute_code' => 'color', 'value' => 93]],
                    ]), JSON_THROW_ON_ERROR)),
                    2 => new ConnectorHttpResult(200, [], json_encode(AdobeProductCommandTestFixtures::remoteProductPayload([
                        'sku' => $redSku,
                        'name' => 'Configurable Product',
                        'visibility' => 1,
                        'custom_attributes' => [['attribute_code' => 'color', 'value' => 94]],
                    ]), JSON_THROW_ON_ERROR)),
                    3 => new ConnectorHttpResult(200, [], json_encode(AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku), JSON_THROW_ON_ERROR)),
                    4 => new ConnectorHttpResult(200, [], json_encode(AdobeConfigurableCommandTestFixtures::remoteOptionsPayload(), JSON_THROW_ON_ERROR)),
                    5, 6 => new ConnectorHttpResult(200, [], json_encode([
                        ['sku' => $blueSku],
                        ['sku' => $redSku],
                    ], JSON_THROW_ON_ERROR)),
                    default => new ConnectorHttpResult(200, [], '[]'),
                };
            },
        );

        $result = $coordinator->execute(
            $workspace->id,
            $account->id,
            AdobeConfigurableCommandTestFixtures::configurableSemanticResult(
                $product->id,
                [
                    ['variant_id' => (string) $variants[0]->id, 'sku' => $variants[0]->sku, 'color' => 'blue', 'color_index' => '93'],
                    ['variant_id' => (string) $variants[1]->id, 'sku' => $variants[1]->sku, 'color' => 'red', 'color_index' => '94'],
                ],
            ),
            'UAH',
            $this->metadataFixture(),
            new SyncLiveConsequentialWriteGateStub(true),
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $result->outcome);
        $this->assertGreaterThan(0, $transport->sendCount);
    }

    /**
     * @return array{0: AdobeConfigurableProductCommandCoordinator, 1: RecordingConnectorHttpTransport}
     */
    private function coordinatorStack(
        ?\Closure $responder = null,
        ?AdobeProductOwnershipTrustPolicy $ownershipPolicy = null,
    ): array {
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
        $ownershipPolicy ??= new ConservativeAdobeProductOwnershipTrustPolicy;
        $persister = new AdobeProductExternalRecordLinkPersister($linkGuard);
        $comparator = new AdobeProductRemoteStateComparator;
        $optionReader = new AdobeConfigurableRemoteOptionStateReader;

        $coordinator = new AdobeConfigurableProductCommandCoordinator(
            new AdobeConfigurableDesiredStateCompiler(new AdobeConfigurableParentSkuGenerator),
            new AdobeProductSimpleCommandExecutor(
                new AdobeProductDesiredStateCompiler,
                app(AdobePaaSRequestContextFactory::class),
                $client,
                $comparator,
                $linkGuard,
                $persister,
                $ownershipPolicy,
            ),
            new AdobeConfigurableParentCommandExecutor(
                app(AdobePaaSRequestContextFactory::class),
                $client,
                $comparator,
                $linkGuard,
                $persister,
                $ownershipPolicy,
            ),
            new AdobeConfigurableOptionCommandExecutor(
                app(AdobePaaSRequestContextFactory::class),
                $client,
                $optionReader,
            ),
            new AdobeConfigurableChildLinkCommandExecutor(
                app(AdobePaaSRequestContextFactory::class),
                $client,
                $optionReader,
            ),
            new AdobeConfigurableInactiveLinkedVariantLifecycleExecutor(
                app(AdobePaaSRequestContextFactory::class),
                $client,
                $normalizer,
                $comparator,
                $linkGuard,
            ),
            new AdobeConfigurableAppliedStateAggregator,
            $linkGuard,
        );

        return [$coordinator, $transport];
    }

    /**
     * @return array{0: AdobeConfigurableParentCommandExecutor, 1: RecordingConnectorHttpTransport}
     */
    private function parentExecutorStack(?\Closure $responder = null): array
    {
        [$coordinator, $transport] = $this->coordinatorStack($responder);

        return [new AdobeConfigurableParentCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductRemoteStateClient(
                app(AdobePaaSRequestContextFactory::class),
                new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
                $transport,
                new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
            ),
            new AdobeProductRemoteStateComparator,
            new AdobeProductExternalRecordLinkGuard,
            new AdobeProductExternalRecordLinkPersister(new AdobeProductExternalRecordLinkGuard),
            new ConservativeAdobeProductOwnershipTrustPolicy,
        ), $transport];
    }

    private function configurableInput(Workspace $workspace, ConnectorAccount $account, Product $product): AdobeConfigurableCommandInput
    {
        $semantic = AdobeConfigurableCommandTestFixtures::configurableSemanticResult($product->id);
        $compiler = new AdobeConfigurableDesiredStateCompiler(new AdobeConfigurableParentSkuGenerator);
        $desired = $compiler->compile($semantic, $workspace->id, $this->metadataFixture());

        return new AdobeConfigurableCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: $semantic,
            desiredState: $desired,
            adobeBaseCurrency: 'UAH',
            metadata: $this->metadataFixture(),
        );
    }

    /**
     * @return array{0: Product, 1: list<ProductVariant>}
     */
    private function createConfigurableProduct(Workspace $workspace, string $productSku = 'CFG-PRODUCT'): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $productSku,
            'name' => 'Configurable Product',
            'is_active' => true,
        ]);

        $variants = [];

        foreach ([['VAR-BLUE', 'blue'], ['VAR-RED', 'red']] as [$suffix, $color]) {
            $variantSku = $productSku.'-'.$suffix;
            $variant = ProductVariant::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'product_id' => $product->id,
                'onec_guid' => (string) Str::uuid(),
                'sku' => $variantSku,
                'is_active' => true,
                'base_price_cache' => 100,
            ]);

            VariantFieldValue::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'variant_id' => $variant->id,
                'field_binding_id' => $this->productVariantBinding('color')->id,
                'value_text' => $color,
            ]);

            $this->attachVariantPrice($workspace, $variant, 100);
            $variants[] = $variant;
        }

        return [$product, $variants];
    }

    /**
     * @return array{0: Product, 1: ProductVariant}
     */
    private function createSimpleProductWithVariant(Workspace $workspace): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SIMPLE-PRODUCT',
            'name' => 'Simple Product',
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SIMPLE-SKU',
            'is_active' => true,
            'base_price_cache' => 100,
        ]);

        $this->attachVariantPrice($workspace, $variant, 100);

        return [$product, $variant];
    }

    private function attachVariantPrice(Workspace $workspace, ProductVariant $variant, float $price): void
    {
        $priceList = PriceList::withoutWorkspaceScope()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'is_default' => true],
            ['name' => 'Workspace Default', 'currency' => 'UAH', 'priority' => 0, 'status' => PriceListStatus::Active],
        );

        PriceListItem::withoutWorkspaceScope()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'price_list_id' => $priceList->id,
                'product_variant_id' => $variant->id,
                'quantity_min' => 1,
            ],
            ['price' => $price, 'status' => PriceListItemStatus::Active],
        );
    }

    private function metadataFixture(): AdobeProductExportExecutionMetadata
    {
        return new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: 4,
            attributeSets: [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            attributes: [
                'name' => new AdobeAttributeMetadata(71, 'name', 'text', 'global', []),
                'sku' => new AdobeAttributeMetadata(74, 'sku', 'text', 'global', []),
                'status' => new AdobeAttributeMetadata(97, 'status', 'select', 'global', ['1' => 'Enabled']),
                'color' => new AdobeAttributeMetadata(100, 'color', 'select', 'global', ['93' => 'Blue', '94' => 'Red']),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function simpleSnapshot(): array
    {
        return [
            'version' => 'platform.sync-run-input.v1',
            'data_domain' => 'products',
            'semantic_operation' => 'export',
            'external_context' => [],
            'selection' => ['mode' => 'all_products'],
            'field_mappings' => [
                ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
                ['field_binding_id' => $this->productVariantBinding('sku')->id, 'external_field_key' => 'sku'],
                ['field_binding_id' => $this->productBinding('status')->id, 'external_field_key' => 'status'],
            ],
            'connector_execution_configuration' => ['attribute_set_id' => 4],
        ];
    }
}
