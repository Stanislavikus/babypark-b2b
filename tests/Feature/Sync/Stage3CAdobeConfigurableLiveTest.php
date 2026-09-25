<?php

namespace Tests\Feature\Sync;

use App\Enums\ExternalRecordLinkTrustOrigin;
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
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableChildLinkDesiredState;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableCommandEvidence;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableCommandInput;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableInactiveLinkedVariantLifecycleExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableOptionCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableParentCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableParentSkuGenerator;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableProductCommandCoordinator;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableProductExecutionResult;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableRemoteOptionStateReader;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductCommandCompilationException;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductCommandRequestFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkGuard;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersister;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductOwnershipTrustPolicy;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductParentDesiredState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteGetClassifier;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateClient;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateComparator;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateNormalizer;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandInput;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductStockSimpleWriteExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductWriteAccessClassification;
use App\Support\Connectors\AdobePaaS\Command\ConservativeAdobeProductOwnershipTrustPolicy;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeConfigurableCommandTestFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\Support\Sync\SyncLiveConsequentialWriteGateStub;
use Tests\TestCase;

class Stage3CAdobeConfigurableLiveTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use CreatesMerchantConfirmedExternalRecordLinks;
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
    public function invalid_child_configurable_value_index_fails_closed_before_http(): void
    {
        $semantic = AdobeConfigurableCommandTestFixtures::configurableSemanticResult(
            children: [[
                'variant_id' => '10',
                'sku' => 'CHILD-BLUE',
                'color' => 'blue',
                'color_index' => '1e3',
            ]],
        );

        $compiler = new AdobeProductDesiredStateCompiler;

        try {
            $compiler->compileSimpleChildFromSemanticResult($semantic, '10');
            $this->fail('Expected AdobeProductCommandCompilationException for invalid value_index.');
        } catch (AdobeProductCommandCompilationException $exception) {
            $this->assertStringContainsString('value_index', $exception->getMessage());
        }

        $transport = new RecordingConnectorHttpTransport(fn (): ConnectorHttpResult => new ConnectorHttpResult(500, [], '{}'));

        $this->app->instance(ConnectorHttpTransport::class, $transport);
        $executor = $this->app->make(AdobeProductSimpleCommandExecutor::class);

        $result = $executor->executeSimpleChild(
            new AdobeProductSimpleCommandInput(
                workspaceId: (string) Str::uuid(),
                connectorAccountId: (string) Str::uuid(),
                semanticResult: $semantic,
                adobeBaseCurrency: 'UAH',
            ),
            '10',
        );

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('semantic_compilation_failed', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function compiler_uses_trusted_existing_parent_sku_instead_of_generated_identity(): void
    {
        $workspace = $this->defaultWorkspace();
        $compiler = new AdobeConfigurableDesiredStateCompiler(new AdobeConfigurableParentSkuGenerator);

        $desired = $compiler->compile(
            AdobeConfigurableCommandTestFixtures::configurableSemanticResult(),
            $workspace->id,
            $this->metadataFixture(),
            'MERCHANT-PARENT-SKU',
        );

        $this->assertSame('MERCHANT-PARENT-SKU', $desired->parentSku);
        $this->assertSame('MERCHANT-PARENT-SKU', $desired->parent->sku);
    }

    #[Test]
    public function parent_normalizer_preserves_fresh_magento_logical_entity_id(): void
    {
        $normalizer = new AdobeProductRemoteStateNormalizer;

        $observed = $normalizer->normalizeParent(
            AdobeConfigurableCommandTestFixtures::remoteParentPayload('MERCHANT-PARENT-SKU', ['id' => 42]),
            'MERCHANT-PARENT-SKU',
        );

        $this->assertNotNull($observed);
        $this->assertSame(42, $observed->entityId);
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
    public function legacy_parent_link_without_provenance_is_not_trusted_with_zero_write(): void
    {
        [$parentExecutor, $transport] = $this->parentExecutorStack();
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-TARGET');

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'stale-parent-sku',
        ]);

        $result = $parentExecutor->execute($this->configurableInput($workspace, $account, $product));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function trusted_parent_identity_mismatch_is_zero_write(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-PARENT-ID');
        $parentSku = 'MERCHANT-PARENT-SKU';

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace,
            $account->id,
            $product,
            $parentSku,
            '42',
        ));

        $transport = new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                [],
                json_encode(AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 43]), JSON_THROW_ON_ERROR),
            ),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeConfigurableParentCommandExecutor::class)->execute(
            $this->configurableInput($workspace, $account, $product, $parentSku, new SyncLiveConsequentialWriteGateStub(true)),
        );

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('configurable_parent_identity_mismatch', $result->reasonCode);
        $this->assertSame(1, $transport->sendCount);
        $this->assertSame('GET', $transport->recordedRequests[0]->request->getMethod());
    }

    #[Test]
    public function trusted_parent_drift_with_unmaterialized_media_role_labels_is_zero_write(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-PARENT-MEDIA');
        $parentSku = 'MERCHANT-PARENT-SKU';

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace,
            $account->id,
            $product,
            $parentSku,
            '42',
        ));

        $transport = new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                [],
                json_encode(AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, [
                    'id' => 42,
                    'name' => 'Remote Parent',
                    'media_gallery_entries' => [[
                        'id' => 501,
                        'label' => 'Merchant media label',
                        'types' => ['image', 'small_image', 'thumbnail'],
                    ]],
                    'custom_attributes' => [],
                ]), JSON_THROW_ON_ERROR),
            ),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeConfigurableParentCommandExecutor::class)->execute(
            $this->configurableInput($workspace, $account, $product, $parentSku, new SyncLiveConsequentialWriteGateStub(true)),
        );

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('configurable_parent_media_role_label_side_effect_not_safe', $result->reasonCode);
        $this->assertSame(0, $result->consequentialWriteAttempts);
        $this->assertSame(['GET'], array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function trusted_parent_exact_state_is_verified_no_op(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-PARENT-NOOP');
        $parentSku = 'MERCHANT-PARENT-SKU';

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace,
            $account->id,
            $product,
            $parentSku,
            '42',
        ));

        $transport = new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                [],
                json_encode(AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 42]), JSON_THROW_ON_ERROR),
            ),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeConfigurableParentCommandExecutor::class)->execute(
            $this->configurableInput($workspace, $account, $product, $parentSku, new SyncLiveConsequentialWriteGateStub(true)),
        );

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame('configurable_parent_state_already_matches', $result->reasonCode);
        $this->assertSame(0, $result->consequentialWriteAttempts);
        $this->assertSame(1, $transport->sendCount);
    }

    #[Test]
    public function trusted_parent_drift_uses_one_put_and_fresh_get_verification(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-PARENT-WRITE');
        $parentSku = 'MERCHANT-PARENT-SKU';

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace,
            $account->id,
            $product,
            $parentSku,
            '42',
        ));

        $transport = new RecordingConnectorHttpTransport(function ($request, int $count) use ($parentSku): ConnectorHttpResult {
            if ($count === 1) {
                return new ConnectorHttpResult(200, [], json_encode(
                    AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 42, 'name' => 'Remote Parent']),
                    JSON_THROW_ON_ERROR,
                ));
            }

            if ($count === 2) {
                return new ConnectorHttpResult(200, [], '{}');
            }

            return new ConnectorHttpResult(200, [], json_encode(
                AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 42]),
                JSON_THROW_ON_ERROR,
            ));
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeConfigurableParentCommandExecutor::class)->execute(
            $this->configurableInput($workspace, $account, $product, $parentSku, new SyncLiveConsequentialWriteGateStub(true)),
        );

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame('stock_write_verified', $result->reasonCode);
        $this->assertSame(1, $result->consequentialWriteAttempts);
        $this->assertSame(1, $result->reconciliationGetAttempts);
        $this->assertSame(['GET', 'PUT', 'GET'], array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function trusted_parent_structured_permission_denial_is_machine_classified(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-PARENT-DENIED');
        $parentSku = 'MERCHANT-PARENT-SKU';

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace,
            $account->id,
            $product,
            $parentSku,
            '42',
        ));

        $transport = new RecordingConnectorHttpTransport(function ($outbound, int $count) use ($parentSku): ConnectorHttpResult {
            if ($count === 1) {
                return new ConnectorHttpResult(200, [], json_encode(
                    AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 42, 'name' => 'Remote Parent']),
                    JSON_THROW_ON_ERROR,
                ));
            }

            return new ConnectorHttpResult(403, [], json_encode([
                'message' => 'Consumer is not authorized.',
                'parameters' => ['resources' => 'Magento_Catalog::products'],
            ], JSON_THROW_ON_ERROR));
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeConfigurableParentCommandExecutor::class)->execute(
            $this->configurableInput($workspace, $account, $product, $parentSku, new SyncLiveConsequentialWriteGateStub(true)),
        );

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('stock_write_permission_denied', $result->reasonCode);
        $this->assertSame(AdobeProductWriteAccessClassification::PermissionDenied, $result->writeAccessClassification);
        $this->assertSame(1, $result->consequentialWriteAttempts);
        $this->assertSame(0, $result->reconciliationGetAttempts);
        $this->assertSame(['GET', 'PUT'], array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function configurable_live_mapping_preserves_write_access_classification(): void
    {
        $evidence = new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_parent',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
            reasonCode: 'stock_write_permission_denied',
            subjectSku: 'MERCHANT-PARENT-SKU',
            consequentialWriteAttempts: 1,
            ownershipTrustSatisfied: true,
            writeAccessClassification: AdobeProductWriteAccessClassification::PermissionDenied,
        );
        $execution = new AdobeConfigurableProductExecutionResult(
            outcome: SyncLiveOutcome::NotApplied,
            commandEvidence: [$evidence],
        );
        $method = new \ReflectionMethod(AdobeProductExportLiveCapability::class, 'mapConfigurableResult');
        $method->setAccessible(true);

        $result = $method->invoke(
            app(AdobeProductExportLiveCapability::class),
            $execution,
            AdobeConfigurableCommandTestFixtures::configurableSemanticResult(),
        );

        $this->assertSame(SyncLiveOutcome::NotApplied, $result->outcome);
        $this->assertSame('stock_write_permission_denied', $result->findings[0]->context['reason_code']);
        $this->assertSame('permission_denied', $result->findings[0]->context['write_access_classification']);
        $this->assertSame(1, $result->findings[0]->context['consequential_write_attempts']);
    }

    #[Test]
    public function parent_create_without_current_attribute_metadata_is_fail_closed_with_zero_write(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-CONSERVATIVE');

        [$parentExecutor, $transport] = $this->parentExecutorStack();

        $result = $parentExecutor->execute($this->configurableInput($workspace, $account, $product));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('adobe_create_attribute_set_unavailable', $result->reasonCode);
        $this->assertSame(0, ExternalRecordLink::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function coordinator_preflights_invalid_parent_before_any_child_http(): void
    {
        [$coordinator, $transport] = $this->coordinatorStack(
            fn (): ConnectorHttpResult => throw new \RuntimeException('No HTTP is allowed for an invalid trusted parent discriminator.'),
        );

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-PREFLIGHT-PARENT');
        $parentSku = 'MERCHANT-PARENT-SKU';

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace,
            $account->id,
            $product,
            $parentSku,
            'invalid-discriminator',
        ));

        foreach ($variants as $index => $variant) {
            ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
                $workspace,
                $account->id,
                $variant,
                $variant->sku,
                (string) (101 + $index),
            ));
        }

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

        $this->assertSame(SyncLiveOutcome::NotApplied, $result->outcome);
        $this->assertCount(1, $result->commandEvidence);
        $this->assertSame('configurable_parent', $result->commandEvidence[0]->commandKind);
        $this->assertSame('trusted_parent_discriminator_invalid', $result->commandEvidence[0]->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function coordinator_blocks_unsafe_parent_drift_before_any_child_http(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-PREFLIGHT-MEDIA');
        $parentSku = 'MERCHANT-PARENT-SKU';

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace,
            $account->id,
            $product,
            $parentSku,
            '100',
        ));

        foreach ($variants as $index => $variant) {
            ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
                $workspace,
                $account->id,
                $variant,
                $variant->sku,
                (string) (101 + $index),
            ));
        }

        [$coordinator, $transport] = $this->coordinatorStack(function ($outbound) use ($parentSku): ConnectorHttpResult {
            $request = $outbound->request;
            $uri = (string) $request->getUri();

            $this->assertSame('GET', $request->getMethod());
            $this->assertStringContainsString('/V1/products/'.rawurlencode($parentSku), $uri);

            return new ConnectorHttpResult(200, [], json_encode(
                AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, [
                    'id' => 100,
                    'name' => 'Remote Parent',
                    'media_gallery_entries' => [[
                        'id' => 501,
                        'label' => 'Merchant media label',
                        'types' => ['image', 'small_image', 'thumbnail'],
                    ]],
                    'custom_attributes' => [],
                ]),
                JSON_THROW_ON_ERROR,
            ));
        });

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

        $this->assertSame(SyncLiveOutcome::NotApplied, $result->outcome);
        $this->assertCount(1, $result->commandEvidence);
        $this->assertSame('configurable_parent_media_role_label_side_effect_not_safe', $result->commandEvidence[0]->reasonCode);
        $this->assertSame(0, $result->commandEvidence[0]->consequentialWriteAttempts);
        $this->assertSame(['GET'], array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function coordinator_stops_all_writes_when_child_has_no_trusted_link(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-TARGET');
        $parentSku = 'MERCHANT-PARENT-SKU';

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace,
            $account->id,
            $product,
            $parentSku,
            '100',
        ));

        [$coordinator, $transport] = $this->coordinatorStack(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                [],
                json_encode(AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 100]), JSON_THROW_ON_ERROR),
            ),
        );

        $result = $coordinator->execute(
            $workspace->id,
            $account->id,
            AdobeConfigurableCommandTestFixtures::configurableSemanticResult($product->id),
            'UAH',
            $this->metadataFixture(),
            null,
        );

        $this->assertSame(SyncLiveOutcome::NotApplied, $result->outcome);
        $this->assertTrue(collect($result->commandEvidence)->contains(
            fn ($entry) => $entry->commandKind === 'simple_child'
                && $entry->reasonCode === 'adobe_create_attribute_set_unavailable',
        ));
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function classification_transition_simple_semantic_with_trusted_parent_is_not_applied(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variant] = $this->createSimpleProductWithVariant($workspace);

        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);
        ExternalRecordLink::query()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $workspace,
                $account->id,
                $product,
                $parentSku,
                'disc-'.$parentSku,
            ),
        );

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
    public function merchant_confirmed_configurable_links_with_invalid_discriminators_fail_closed_before_http(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-INVALID-DISC');
        $parentSku = 'MERCHANT-PARENT-SKU';

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace,
            $account->id,
            $product,
            $parentSku,
            '100',
        ));

        foreach ($variants as $variant) {
            ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
                $workspace,
                $account->id,
                $variant,
                $variant->sku,
                'disc-'.$variant->sku,
            ));
        }

        [$coordinator, $transport] = $this->coordinatorStack(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                [],
                json_encode(AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 100]), JSON_THROW_ON_ERROR),
            ),
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

        $this->assertSame(SyncLiveOutcome::NotApplied, $result->outcome);
        $this->assertTrue(collect($result->commandEvidence)->contains(
            fn ($entry) => $entry->commandKind === 'simple_child'
                && $entry->reasonCode === 'trusted_child_identity_invalid',
        ));
        $this->assertSame(['GET'], array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function merchant_confirmed_existing_family_uses_trusted_parent_sku_and_verified_no_op_path(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-LINKED');
        $parentSku = 'MERCHANT-PARENT-SKU';

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace,
            $account->id,
            $product,
            $parentSku,
            '100',
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace,
            $account->id,
            $variants[0],
            $variants[0]->sku,
            '101',
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace,
            $account->id,
            $variants[1],
            $variants[1]->sku,
            '102',
        ));

        $childIds = [
            $variants[0]->sku => [101, 93],
            $variants[1]->sku => [102, 94],
        ];
        $transport = new RecordingConnectorHttpTransport(function ($outbound) use ($parentSku, $childIds, $variants): ConnectorHttpResult {
            $request = $outbound->request;
            $uri = (string) $request->getUri();

            if ($request->getMethod() !== 'GET') {
                return new ConnectorHttpResult(500, [], '{}');
            }

            foreach ($childIds as $sku => [$entityId, $colorIndex]) {
                if (str_ends_with($uri, '/V1/products/'.rawurlencode($sku))) {
                    return new ConnectorHttpResult(200, [], json_encode([
                        'id' => $entityId,
                        'sku' => $sku,
                        'name' => 'Configurable Product',
                        'attribute_set_id' => 4,
                        'type_id' => 'simple',
                        'status' => 1,
                        'visibility' => 1,
                        'price' => 100.0,
                        'custom_attributes' => [
                            ['attribute_code' => 'color', 'value' => $colorIndex],
                        ],
                        'media_gallery_entries' => [],
                    ], JSON_THROW_ON_ERROR));
                }
            }

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($parentSku))) {
                return new ConnectorHttpResult(200, [], json_encode(
                    AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 100]),
                    JSON_THROW_ON_ERROR,
                ));
            }

            if (str_contains($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/options/all')) {
                return new ConnectorHttpResult(200, [], json_encode(
                    AdobeConfigurableCommandTestFixtures::remoteOptionsPayload(),
                    JSON_THROW_ON_ERROR,
                ));
            }

            if (str_contains($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/children')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    ['sku' => $variants[0]->sku],
                    ['sku' => $variants[1]->sku],
                ], JSON_THROW_ON_ERROR));
            }

            return new ConnectorHttpResult(404, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $coordinator = app(AdobeConfigurableProductCommandCoordinator::class);
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
        $this->assertTrue(collect($result->commandEvidence)->contains(
            fn ($entry) => $entry->commandKind === 'configurable_parent'
                && $entry->reasonCode === 'configurable_parent_state_already_matches'
                && $entry->subjectSku === $parentSku,
        ));
        $this->assertTrue(collect($result->commandEvidence)->contains(
            fn ($entry) => $entry->commandKind === 'configurable_option'
                && $entry->reasonCode === 'configurable_option_no_op'
                && $entry->subjectSku === $parentSku,
        ));
        $this->assertSame(0, collect($transport->recordedRequests)->filter(
            fn ($entry) => $entry->request->getMethod() !== 'GET',
        )->count());
    }

    #[Test]
    public function completed_platform_created_family_stays_on_no_write_linked_path(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-PLATFORM-COMPLETE');
        $parentSku = 'PLATFORM-PARENT-SKU';

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => $parentSku,
            'trust_origin' => ExternalRecordLinkTrustOrigin::PlatformCreated->value,
            'external_record_discriminator' => '100',
            'established_at' => now(),
        ]);

        foreach ($variants as $index => $variant) {
            ExternalRecordLink::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'connector_account_id' => $account->id,
                'product_variant_id' => $variant->id,
                'external_identifier' => $variant->sku,
                'trust_origin' => ExternalRecordLinkTrustOrigin::PlatformCreated->value,
                'external_record_discriminator' => (string) (101 + $index),
                'established_at' => now(),
            ]);
        }

        $childIds = [
            $variants[0]->sku => [101, 93],
            $variants[1]->sku => [102, 94],
        ];

        $transport = new RecordingConnectorHttpTransport(function ($outbound) use ($parentSku, $childIds, $variants): ConnectorHttpResult {
            $request = $outbound->request;
            $uri = (string) $request->getUri();

            if ($request->getMethod() !== 'GET') {
                return new ConnectorHttpResult(500, [], '{}');
            }

            foreach ($childIds as $sku => [$entityId, $colorIndex]) {
                if (str_ends_with($uri, '/V1/products/'.rawurlencode($sku))) {
                    return new ConnectorHttpResult(200, [], json_encode([
                        'id' => $entityId,
                        'sku' => $sku,
                        'name' => 'Configurable Product',
                        'attribute_set_id' => 4,
                        'type_id' => 'simple',
                        'status' => 1,
                        'visibility' => 1,
                        'price' => 100.0,
                        'custom_attributes' => [
                            ['attribute_code' => 'color', 'value' => $colorIndex],
                        ],
                        'media_gallery_entries' => [],
                    ], JSON_THROW_ON_ERROR));
                }
            }

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($parentSku))) {
                return new ConnectorHttpResult(200, [], json_encode(
                    AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 100]),
                    JSON_THROW_ON_ERROR,
                ));
            }

            if (str_contains($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/options/all')) {
                return new ConnectorHttpResult(200, [], json_encode(
                    AdobeConfigurableCommandTestFixtures::remoteOptionsPayload(),
                    JSON_THROW_ON_ERROR,
                ));
            }

            if (str_contains($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/children')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    ['sku' => $variants[0]->sku],
                    ['sku' => $variants[1]->sku],
                ], JSON_THROW_ON_ERROR));
            }

            return new ConnectorHttpResult(404, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeConfigurableProductCommandCoordinator::class)->execute(
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
        $this->assertSame(0, collect($transport->recordedRequests)->filter(
            fn ($entry) => $entry->request->getMethod() !== 'GET',
        )->count());
        $this->assertTrue(collect($result->commandEvidence)->contains(
            fn ($entry) => $entry->commandKind === 'configurable_parent'
                && $entry->reasonCode === 'configurable_parent_state_already_matches',
        ));
    }

    #[Test]
    public function linked_family_reconciles_existing_option_drift_with_one_put(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-OPTION-DRIFT');
        $parentSku = 'MERCHANT-PARENT-SKU';

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace, $account->id, $product, $parentSku, '100',
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace, $account->id, $variants[0], $variants[0]->sku, '101',
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace, $account->id, $variants[1], $variants[1]->sku, '102',
        ));

        $childState = [
            $variants[0]->sku => [101, 93],
            $variants[1]->sku => [102, 94],
        ];
        $optionUpdated = false;

        $transport = new RecordingConnectorHttpTransport(function ($outbound) use (
            $parentSku,
            $variants,
            $childState,
            &$optionUpdated,
        ): ConnectorHttpResult {
            $request = $outbound->request;
            $method = $request->getMethod();
            $uri = (string) $request->getUri();

            foreach ($childState as $sku => [$entityId, $colorIndex]) {
                if (str_ends_with($uri, '/V1/products/'.rawurlencode($sku))) {
                    $this->assertSame('GET', $method);

                    return new ConnectorHttpResult(200, [], json_encode([
                        'id' => $entityId,
                        'sku' => $sku,
                        'name' => 'Configurable Product',
                        'attribute_set_id' => 4,
                        'type_id' => 'simple',
                        'status' => 1,
                        'visibility' => 1,
                        'price' => 100.0,
                        'custom_attributes' => [
                            ['attribute_code' => 'color', 'value' => $colorIndex],
                        ],
                        'media_gallery_entries' => [],
                    ], JSON_THROW_ON_ERROR));
                }
            }

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($parentSku))) {
                $this->assertSame('GET', $method);

                return new ConnectorHttpResult(200, [], json_encode(
                    AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 100]),
                    JSON_THROW_ON_ERROR,
                ));
            }

            if (str_contains($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/options/201')) {
                $this->assertSame('PUT', $method);
                $optionUpdated = true;

                return new ConnectorHttpResult(200, [], '1');
            }

            if (str_contains($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/options/all')) {
                $this->assertSame('GET', $method);
                $options = AdobeConfigurableCommandTestFixtures::remoteOptionsPayload();
                $options[0]['position'] = $optionUpdated ? 0 : 1;

                return new ConnectorHttpResult(200, [], json_encode($options, JSON_THROW_ON_ERROR));
            }

            if (str_contains($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/children')) {
                $this->assertSame('GET', $method);

                return new ConnectorHttpResult(200, [], json_encode([
                    ['sku' => $variants[0]->sku],
                    ['sku' => $variants[1]->sku],
                ], JSON_THROW_ON_ERROR));
            }

            return new ConnectorHttpResult(404, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeConfigurableProductCommandCoordinator::class)->execute(
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
        $this->assertTrue(collect($result->commandEvidence)->contains(
            fn ($entry) => $entry->commandKind === 'configurable_option'
                && $entry->reasonCode === 'configurable_option_put_reconciled'
                && $entry->consequentialWriteAttempts === 1
                && $entry->reconciliationGetAttempts === 1,
        ));
        $this->assertSame(1, collect($transport->recordedRequests)->filter(
            fn ($entry) => $entry->request->getMethod() === 'PUT'
                && str_contains((string) $entry->request->getUri(), '/options/201'),
        )->count());
        $this->assertFalse(collect($transport->recordedRequests)->contains(
            fn ($entry) => $entry->request->getMethod() === 'POST',
        ));
    }

    #[Test]
    public function linked_family_updates_children_before_parent_and_keeps_existing_structure_no_op(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-DRIFT');
        $parentSku = 'MERCHANT-PARENT-SKU';

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace, $account->id, $product, $parentSku, '100',
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace, $account->id, $variants[0], $variants[0]->sku, '101',
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace, $account->id, $variants[1], $variants[1]->sku, '102',
        ));

        $childState = [
            $variants[0]->sku => [
                'id' => 101,
                'color' => 93,
                'name' => 'Merchant Blue Child',
                'updated' => false,
            ],
            $variants[1]->sku => [
                'id' => 102,
                'color' => 94,
                'name' => 'Merchant Red Child',
                'updated' => false,
            ],
        ];
        $parentUpdated = false;

        $transport = new RecordingConnectorHttpTransport(function ($outbound) use (
            $parentSku,
            $variants,
            &$childState,
            &$parentUpdated,
        ): ConnectorHttpResult {
            $request = $outbound->request;
            $method = $request->getMethod();
            $uri = (string) $request->getUri();

            foreach ($childState as $sku => &$state) {
                if (! str_ends_with($uri, '/V1/products/'.rawurlencode($sku))) {
                    continue;
                }

                if ($method === 'PUT') {
                    $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    $this->assertSame($state['name'], $payload['product']['name'] ?? null);
                    $state['updated'] = true;

                    return new ConnectorHttpResult(200, [], '{}');
                }

                if ($method === 'GET') {
                    return new ConnectorHttpResult(200, [], json_encode([
                        'id' => $state['id'],
                        'sku' => $sku,
                        'name' => $state['name'],
                        'attribute_set_id' => 4,
                        'type_id' => 'simple',
                        'status' => 1,
                        'visibility' => 1,
                        'price' => $state['updated'] ? 100.0 : 90.0,
                        'custom_attributes' => [
                            ['attribute_code' => 'color', 'value' => $state['color']],
                        ],
                        'media_gallery_entries' => [],
                    ], JSON_THROW_ON_ERROR));
                }
            }
            unset($state);

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($parentSku))) {
                if ($method === 'PUT') {
                    $parentUpdated = true;

                    return new ConnectorHttpResult(200, [], '{}');
                }

                if ($method === 'GET') {
                    return new ConnectorHttpResult(200, [], json_encode(
                        AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, [
                            'id' => 100,
                            'name' => $parentUpdated ? 'Configurable Product' : 'Remote Parent',
                        ]),
                        JSON_THROW_ON_ERROR,
                    ));
                }
            }

            if ($method === 'GET' && str_contains($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/options/all')) {
                return new ConnectorHttpResult(200, [], json_encode(
                    AdobeConfigurableCommandTestFixtures::remoteOptionsPayload(),
                    JSON_THROW_ON_ERROR,
                ));
            }

            if ($method === 'GET' && str_contains($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/children')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    ['sku' => $variants[0]->sku],
                    ['sku' => $variants[1]->sku],
                ], JSON_THROW_ON_ERROR));
            }

            return new ConnectorHttpResult(500, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeConfigurableProductCommandCoordinator::class)->execute(
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
        $this->assertSame(2, collect($result->commandEvidence)->filter(
            fn ($entry) => $entry->commandKind === 'simple_child' && $entry->reasonCode === 'stock_write_verified',
        )->count());
        $this->assertTrue(collect($result->commandEvidence)->contains(
            fn ($entry) => $entry->commandKind === 'configurable_parent' && $entry->reasonCode === 'stock_write_verified',
        ));

        $methods = array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        );
        $this->assertSame(3, count(array_filter($methods, fn (string $method): bool => $method === 'PUT')));
        $this->assertNotContains('POST', $methods);

        $putUris = collect($transport->recordedRequests)
            ->filter(fn ($entry) => $entry->request->getMethod() === 'PUT')
            ->map(fn ($entry) => (string) $entry->request->getUri())
            ->values()
            ->all();
        $this->assertStringContainsString(rawurlencode($variants[0]->sku), $putUris[0]);
        $this->assertStringContainsString(rawurlencode($variants[1]->sku), $putUris[1]);
        $this->assertStringContainsString(rawurlencode($parentSku), $putUris[2]);
    }

    #[Test]
    public function linked_family_child_with_unmaterialized_media_role_labels_fails_before_put(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-MEDIA-SIDE-EFFECT');
        $parentSku = 'MERCHANT-PARENT-SKU';

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace, $account->id, $product, $parentSku, '100',
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace, $account->id, $variants[0], $variants[0]->sku, '101',
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace, $account->id, $variants[1], $variants[1]->sku, '102',
        ));

        $childState = [
            $variants[0]->sku => ['id' => 101, 'color' => 93],
            $variants[1]->sku => ['id' => 102, 'color' => 94],
        ];

        $transport = new RecordingConnectorHttpTransport(function ($outbound) use ($childState, $parentSku): ConnectorHttpResult {
            $request = $outbound->request;
            $method = $request->getMethod();
            $uri = (string) $request->getUri();

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($parentSku))) {
                $this->assertSame('GET', $method);

                return new ConnectorHttpResult(200, [], json_encode(
                    AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 100]),
                    JSON_THROW_ON_ERROR,
                ));
            }

            foreach ($childState as $sku => $state) {
                if (! str_ends_with($uri, '/V1/products/'.rawurlencode($sku))) {
                    continue;
                }

                $this->assertSame('GET', $method);

                return new ConnectorHttpResult(200, [], json_encode([
                    'id' => $state['id'],
                    'sku' => $sku,
                    'name' => 'Merchant Child Name',
                    'attribute_set_id' => 4,
                    'type_id' => 'simple',
                    'status' => 1,
                    'visibility' => 1,
                    'price' => 90.0,
                    'custom_attributes' => [
                        ['attribute_code' => 'color', 'value' => $state['color']],
                    ],
                    'media_gallery_entries' => [[
                        'id' => 501,
                        'label' => 'Merchant media label',
                        'types' => ['image', 'small_image', 'thumbnail'],
                    ]],
                ], JSON_THROW_ON_ERROR));
            }

            throw new \RuntimeException('No parent or structure request is allowed after unsafe child evidence.');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeConfigurableProductCommandCoordinator::class)->execute(
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

        $this->assertSame(SyncLiveOutcome::NotApplied, $result->outcome);
        $this->assertCount(1, $result->commandEvidence);
        $this->assertSame('simple_child', $result->commandEvidence[0]->commandKind);
        $this->assertSame(
            'configurable_child_media_role_label_side_effect_not_safe',
            $result->commandEvidence[0]->reasonCode,
        );
        $this->assertSame(0, $result->commandEvidence[0]->consequentialWriteAttempts);
        $this->assertSame(['GET', 'GET'], array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function linked_family_stops_remaining_child_writes_after_known_not_applied_child(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-CHILD-STOP');
        $parentSku = 'MERCHANT-PARENT-SKU';

        $thirdVariant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-CHILD-STOP-VAR-BLUE-2',
            'is_active' => true,
            'base_price_cache' => 100,
        ]);
        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'variant_id' => $thirdVariant->id,
            'field_binding_id' => $this->productVariantBinding('color')->id,
            'value_text' => 'blue',
        ]);
        $this->attachVariantPrice($workspace, $thirdVariant, 100);
        $variants[] = $thirdVariant;

        usort($variants, static fn (ProductVariant $left, ProductVariant $right): int => strcmp((string) $left->id, (string) $right->id));
        [$firstVariant, $refusedVariant, $laterVariant] = $variants;

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace, $account->id, $product, $parentSku, '100',
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace, $account->id, $firstVariant, $firstVariant->sku, '101',
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace, $account->id, $refusedVariant, $refusedVariant->sku, 'invalid-discriminator',
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace, $account->id, $laterVariant, $laterVariant->sku, '103',
        ));

        $updated = false;
        $transport = new RecordingConnectorHttpTransport(function ($outbound) use ($firstVariant, $parentSku, &$updated): ConnectorHttpResult {
            $request = $outbound->request;
            $method = $request->getMethod();
            $uri = (string) $request->getUri();

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($parentSku))) {
                $this->assertSame('GET', $method);

                return new ConnectorHttpResult(200, [], json_encode(
                    AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 100]),
                    JSON_THROW_ON_ERROR,
                ));
            }

            if (! str_ends_with($uri, '/V1/products/'.rawurlencode($firstVariant->sku))) {
                return new ConnectorHttpResult(500, [], '{}');
            }

            if ($method === 'PUT') {
                $updated = true;

                return new ConnectorHttpResult(200, [], '{}');
            }

            if ($method === 'GET') {
                return new ConnectorHttpResult(200, [], json_encode([
                    'id' => 101,
                    'sku' => $firstVariant->sku,
                    'name' => 'Configurable Product',
                    'attribute_set_id' => 4,
                    'type_id' => 'simple',
                    'status' => 1,
                    'visibility' => 1,
                    'price' => $updated ? 100.0 : 90.0,
                    'custom_attributes' => [
                        ['attribute_code' => 'color', 'value' => 93],
                    ],
                    'media_gallery_entries' => [],
                ], JSON_THROW_ON_ERROR));
            }

            return new ConnectorHttpResult(500, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $children = collect($variants)->map(static fn (ProductVariant $variant): array => [
            'variant_id' => (string) $variant->id,
            'sku' => $variant->sku,
            'color' => 'blue',
            'color_index' => '93',
        ])->all();

        $result = app(AdobeConfigurableProductCommandCoordinator::class)->execute(
            $workspace->id,
            $account->id,
            AdobeConfigurableCommandTestFixtures::configurableSemanticResult($product->id, $children),
            'UAH',
            $this->metadataFixture(),
            new SyncLiveConsequentialWriteGateStub(true),
        );

        $this->assertSame(SyncLiveOutcome::NotApplied, $result->outcome);
        $this->assertCount(1, $result->commandEvidence);
        $this->assertSame('trusted_child_identity_invalid', $result->commandEvidence[0]->reasonCode);
        $this->assertSame($refusedVariant->sku, $result->commandEvidence[0]->subjectSku);

        $this->assertSame(0, collect($transport->recordedRequests)->filter(
            fn ($entry) => $entry->request->getMethod() === 'PUT',
        )->count());
        $this->assertFalse(collect($transport->recordedRequests)->contains(
            fn ($entry) => str_contains((string) $entry->request->getUri(), rawurlencode($laterVariant->sku)),
        ));
        $this->assertFalse(collect($transport->recordedRequests)->contains(
            fn ($entry) => $entry->request->getMethod() === 'PUT'
                && str_contains((string) $entry->request->getUri(), rawurlencode($parentSku)),
        ));
    }

    #[Test]
    public function existing_option_update_only_reconciles_non_destructive_drift(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-OPTION-UPDATE');
        $parentSku = 'MERCHANT-PARENT-SKU';
        $input = $this->configurableInput(
            $workspace,
            $account,
            $product,
            $parentSku,
            new SyncLiveConsequentialWriteGateStub(true),
        );
        $desiredOption = $input->desiredState->options[0];
        $updated = false;

        $transport = new RecordingConnectorHttpTransport(function ($outbound) use ($desiredOption, &$updated): ConnectorHttpResult {
            $request = $outbound->request;

            if ($request->getMethod() === 'PUT') {
                $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame((string) $desiredOption->attributeId, $payload['option']['attribute_id'] ?? null);
                $this->assertSame($desiredOption->label, $payload['option']['label'] ?? null);
                $this->assertSame($desiredOption->position, $payload['option']['position'] ?? null);
                $updated = true;

                return new ConnectorHttpResult(200, [], '1');
            }

            return new ConnectorHttpResult(200, [], json_encode([[
                'id' => 201,
                'attribute_id' => (string) $desiredOption->attributeId,
                'label' => $desiredOption->label,
                'position' => $updated ? $desiredOption->position : $desiredOption->position + 1,
                'values' => array_map(
                    static fn ($value): array => ['value_index' => $value->valueIndex],
                    $desiredOption->values,
                ),
            ]], JSON_THROW_ON_ERROR));
        });

        $client = new AdobeProductRemoteStateClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
        );
        $executor = new AdobeConfigurableOptionCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            $client,
            new AdobeConfigurableRemoteOptionStateReader,
        );

        $result = $executor->executeExistingUpdateOnly($input, $desiredOption);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame('configurable_option_put_reconciled', $result->reasonCode);
        $this->assertSame(1, $result->consequentialWriteAttempts);
        $this->assertSame(1, $result->reconciliationGetAttempts);
        $this->assertSame(['GET', 'PUT', 'GET'], array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function existing_option_update_only_never_creates_missing_option(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-OPTION-MISSING');
        $parentSku = 'MERCHANT-PARENT-SKU';
        $input = $this->configurableInput(
            $workspace,
            $account,
            $product,
            $parentSku,
            new SyncLiveConsequentialWriteGateStub(true),
        );
        $desiredOption = $input->desiredState->options[0];

        $transport = new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(200, [], '[]'),
        );
        $client = new AdobeProductRemoteStateClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
        );
        $executor = new AdobeConfigurableOptionCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            $client,
            new AdobeConfigurableRemoteOptionStateReader,
        );

        $result = $executor->executeExistingUpdateOnly($input, $desiredOption);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('configurable_option_create_not_certified', $result->reasonCode);
        $this->assertSame(0, $result->consequentialWriteAttempts);
        $this->assertSame(['GET'], array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function existing_option_update_only_preserves_remote_extra_values_without_put(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-OPTION-REMOVE');
        $parentSku = 'MERCHANT-PARENT-SKU';
        $input = $this->configurableInput(
            $workspace,
            $account,
            $product,
            $parentSku,
            new SyncLiveConsequentialWriteGateStub(true),
        );
        $desiredOption = $input->desiredState->options[0];

        $values = array_map(
            static fn ($value): array => ['value_index' => $value->valueIndex],
            $desiredOption->values,
        );
        $values[] = ['value_index' => 999];

        $transport = new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(200, [], json_encode([[
                'id' => 201,
                'attribute_id' => (string) $desiredOption->attributeId,
                'label' => $desiredOption->label,
                'position' => $desiredOption->position,
                'values' => $values,
            ]], JSON_THROW_ON_ERROR)),
        );
        $client = new AdobeProductRemoteStateClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
        );
        $executor = new AdobeConfigurableOptionCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            $client,
            new AdobeConfigurableRemoteOptionStateReader,
        );

        $result = $executor->executeExistingUpdateOnly($input, $desiredOption);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame('configurable_option_remote_values_preserved', $result->reasonCode);
        $this->assertSame(0, $result->consequentialWriteAttempts);
        $this->assertSame(['GET'], array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function option_no_op_only_rejects_drift_without_option_write(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-OPTION-GATE');
        $parentSku = 'MERCHANT-PARENT-SKU';
        $input = $this->configurableInput(
            $workspace,
            $account,
            $product,
            $parentSku,
            new SyncLiveConsequentialWriteGateStub(true),
        );
        $desiredOption = $input->desiredState->options[0];

        $transport = new RecordingConnectorHttpTransport(fn (): ConnectorHttpResult => new ConnectorHttpResult(
            200,
            [],
            json_encode([[
                'id' => 201,
                'attribute_id' => (string) $desiredOption->attributeId,
                'label' => 'Remote Drift',
                'position' => 0,
                'values' => [['value_index' => 93], ['value_index' => 94]],
            ]], JSON_THROW_ON_ERROR),
        ));
        $client = new AdobeProductRemoteStateClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
        );
        $executor = new AdobeConfigurableOptionCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            $client,
            new AdobeConfigurableRemoteOptionStateReader,
        );

        $result = $executor->executeNoOpOnly($input, $desiredOption);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('configurable_option_mutation_not_certified', $result->reasonCode);
        $this->assertSame(0, $result->consequentialWriteAttempts);
        $this->assertSame(['GET'], array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function trusted_child_relink_verifies_parent_and_child_identity_before_one_post(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-TRUSTED-RELINK');
        $parentSku = 'MERCHANT-PARENT-SKU';
        $child = $variants[0];
        $actor = $this->createWorkspaceActor($workspace);

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace, $account->id, $product, $parentSku, '100', $actor,
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace, $account->id, $child, $child->sku, '101', $actor,
        ));

        $input = $this->configurableInput(
            $workspace,
            $account,
            $product,
            $parentSku,
            new SyncLiveConsequentialWriteGateStub(true),
        );
        $desiredLink = new AdobeConfigurableChildLinkDesiredState(
            variantId: (string) $child->id,
            childSku: $child->sku,
        );
        $linked = false;

        $transport = new RecordingConnectorHttpTransport(function ($outbound) use (
            $parentSku,
            $child,
            &$linked,
        ): ConnectorHttpResult {
            $request = $outbound->request;
            $method = $request->getMethod();
            $uri = (string) $request->getUri();

            if (str_ends_with($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/children')) {
                return new ConnectorHttpResult(
                    200,
                    [],
                    $linked ? json_encode([['sku' => $child->sku]], JSON_THROW_ON_ERROR) : '[]',
                );
            }

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($parentSku))) {
                return new ConnectorHttpResult(200, [], json_encode([
                    'id' => 100,
                    'sku' => $parentSku,
                    'name' => 'Configurable Product',
                    'attribute_set_id' => 4,
                    'type_id' => 'configurable',
                    'status' => 1,
                    'visibility' => 4,
                    'price' => 0,
                    'custom_attributes' => [],
                    'media_gallery_entries' => [],
                ], JSON_THROW_ON_ERROR));
            }

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($child->sku))) {
                return new ConnectorHttpResult(200, [], json_encode([
                    'id' => 101,
                    'sku' => $child->sku,
                    'name' => 'Configurable Product',
                    'attribute_set_id' => 4,
                    'type_id' => 'simple',
                    'status' => 1,
                    'visibility' => 1,
                    'price' => 100,
                    'custom_attributes' => [['attribute_code' => 'color', 'value' => 93]],
                    'media_gallery_entries' => [],
                ], JSON_THROW_ON_ERROR));
            }

            if (str_ends_with($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/child')) {
                $this->assertSame('POST', $method);
                $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($child->sku, $payload['childSku'] ?? null);
                $linked = true;

                return new ConnectorHttpResult(200, [], 'true');
            }

            return new ConnectorHttpResult(404, [], '{}');
        });

        $client = new AdobeProductRemoteStateClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
        );
        $executor = new AdobeConfigurableChildLinkCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            $client,
            new AdobeConfigurableRemoteOptionStateReader,
            new AdobeProductExternalRecordLinkGuard,
        );

        $result = $executor->executeTrustedRelinkOnly($input, $desiredLink);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame('configurable_child_link_reconciled', $result->reasonCode);
        $this->assertSame(1, $result->consequentialWriteAttempts);
        $this->assertSame(1, $result->reconciliationGetAttempts);
        $this->assertSame(1, collect($transport->recordedRequests)->filter(
            fn ($entry) => $entry->request->getMethod() === 'POST',
        )->count());
    }

    #[Test]
    public function trusted_child_relink_identity_mismatch_blocks_post(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-RELINK-MISMATCH');
        $parentSku = 'MERCHANT-PARENT-SKU';
        $child = $variants[0];
        $actor = $this->createWorkspaceActor($workspace);

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace, $account->id, $product, $parentSku, '100', $actor,
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace, $account->id, $child, $child->sku, '101', $actor,
        ));

        $input = $this->configurableInput(
            $workspace,
            $account,
            $product,
            $parentSku,
            new SyncLiveConsequentialWriteGateStub(true),
        );
        $desiredLink = new AdobeConfigurableChildLinkDesiredState(
            variantId: (string) $child->id,
            childSku: $child->sku,
        );

        $transport = new RecordingConnectorHttpTransport(function ($outbound) use ($parentSku, $child): ConnectorHttpResult {
            $uri = (string) $outbound->request->getUri();

            if (str_ends_with($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/children')) {
                return new ConnectorHttpResult(200, [], '[]');
            }

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($parentSku))) {
                return new ConnectorHttpResult(200, [], json_encode([
                    'id' => 100,
                    'sku' => $parentSku,
                    'name' => 'Configurable Product',
                    'attribute_set_id' => 4,
                    'type_id' => 'configurable',
                    'status' => 1,
                    'visibility' => 4,
                    'price' => 0,
                    'custom_attributes' => [],
                    'media_gallery_entries' => [],
                ], JSON_THROW_ON_ERROR));
            }

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($child->sku))) {
                return new ConnectorHttpResult(200, [], json_encode([
                    'id' => 999,
                    'sku' => $child->sku,
                    'name' => 'Configurable Product',
                    'attribute_set_id' => 4,
                    'type_id' => 'simple',
                    'status' => 1,
                    'visibility' => 1,
                    'price' => 100,
                    'custom_attributes' => [['attribute_code' => 'color', 'value' => 93]],
                    'media_gallery_entries' => [],
                ], JSON_THROW_ON_ERROR));
            }

            return new ConnectorHttpResult(500, [], '{}');
        });

        $client = new AdobeProductRemoteStateClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
        );
        $executor = new AdobeConfigurableChildLinkCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            $client,
            new AdobeConfigurableRemoteOptionStateReader,
            new AdobeProductExternalRecordLinkGuard,
        );

        $result = $executor->executeTrustedRelinkOnly($input, $desiredLink);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('configurable_child_pre_relink_identity_mismatch', $result->reasonCode);
        $this->assertSame(0, $result->consequentialWriteAttempts);
        $this->assertFalse(collect($transport->recordedRequests)->contains(
            fn ($entry) => $entry->request->getMethod() === 'POST',
        ));
    }

    #[Test]
    public function linked_family_revalidates_and_repairs_options_after_child_relink_side_effect(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-RELINK-OPTION-SIDE-EFFECT');
        $parentSku = 'MERCHANT-PARENT-SKU';
        $actor = $this->createWorkspaceActor($workspace);

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace, $account->id, $product, $parentSku, '100', $actor,
        ));

        foreach ([101, 102] as $index => $entityId) {
            ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
                $workspace,
                $account->id,
                $variants[$index],
                $variants[$index]->sku,
                (string) $entityId,
                $actor,
            ));
        }

        $semantic = AdobeConfigurableCommandTestFixtures::configurableSemanticResult(
            $product->id,
            [
                ['variant_id' => (string) $variants[0]->id, 'sku' => $variants[0]->sku, 'color' => 'blue', 'color_index' => '93'],
                ['variant_id' => (string) $variants[1]->id, 'sku' => $variants[1]->sku, 'color' => 'red', 'color_index' => '94'],
            ],
        );

        $linked = false;
        $optionRebuilt = false;
        $optionRepaired = false;
        $childState = [
            $variants[0]->sku => [101, 93],
            $variants[1]->sku => [102, 94],
        ];

        $transport = new RecordingConnectorHttpTransport(function ($outbound) use (
            $parentSku,
            $variants,
            $childState,
            &$linked,
            &$optionRebuilt,
            &$optionRepaired,
        ): ConnectorHttpResult {
            $request = $outbound->request;
            $method = $request->getMethod();
            $uri = (string) $request->getUri();

            foreach ($childState as $sku => [$entityId, $colorIndex]) {
                if (str_ends_with($uri, '/V1/products/'.rawurlencode($sku))) {
                    return new ConnectorHttpResult(200, [], json_encode([
                        'id' => $entityId,
                        'sku' => $sku,
                        'name' => 'Configurable Product',
                        'attribute_set_id' => 4,
                        'type_id' => 'simple',
                        'status' => 1,
                        'visibility' => 1,
                        'price' => 100,
                        'custom_attributes' => [
                            ['attribute_code' => 'color', 'value' => $colorIndex],
                        ],
                        'media_gallery_entries' => [],
                    ], JSON_THROW_ON_ERROR));
                }
            }

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($parentSku))) {
                return new ConnectorHttpResult(200, [], json_encode(
                    AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 100]),
                    JSON_THROW_ON_ERROR,
                ));
            }

            if (str_ends_with($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/options/all')) {
                $payload = AdobeConfigurableCommandTestFixtures::remoteOptionsPayload(
                    optionId: $optionRebuilt ? 202 : 201,
                );
                $payload[0]['label'] = $optionRebuilt && ! $optionRepaired ? 'Color' : 'color';

                if (! $linked) {
                    $payload[0]['values'] = [['value_index' => 94]];
                }

                return new ConnectorHttpResult(200, [], json_encode($payload, JSON_THROW_ON_ERROR));
            }

            if (str_ends_with($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/options/202')) {
                $this->assertSame('PUT', $method);
                $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('color', $payload['option']['label'] ?? null);
                $optionRepaired = true;

                return new ConnectorHttpResult(200, [], '1');
            }

            if (str_ends_with($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/children')) {
                $children = $linked
                    ? [['sku' => $variants[0]->sku], ['sku' => $variants[1]->sku]]
                    : [['sku' => $variants[1]->sku]];

                return new ConnectorHttpResult(200, [], json_encode($children, JSON_THROW_ON_ERROR));
            }

            if (str_ends_with($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/child')) {
                $this->assertSame('POST', $method);
                $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($variants[0]->sku, $payload['childSku'] ?? null);
                $linked = true;
                $optionRebuilt = true;

                return new ConnectorHttpResult(200, [], 'true');
            }

            return new ConnectorHttpResult(404, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeConfigurableProductCommandCoordinator::class)->execute(
            $workspace->id,
            $account->id,
            $semantic,
            'UAH',
            $this->metadataFixture(),
            new SyncLiveConsequentialWriteGateStub(true),
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $result->outcome);
        $this->assertTrue(collect($result->commandEvidence)->contains(
            fn ($entry) => $entry->commandKind === 'child_link'
                && $entry->subjectSku === $variants[0]->sku
                && $entry->reasonCode === 'configurable_child_link_reconciled'
                && $entry->consequentialWriteAttempts === 1,
        ));
        $this->assertTrue(collect($result->commandEvidence)->contains(
            fn ($entry) => $entry->commandKind === 'configurable_option'
                && $entry->reasonCode === 'configurable_option_put_reconciled'
                && $entry->configurableOptionId === 202
                && $entry->consequentialWriteAttempts === 1,
        ));
        $this->assertSame(1, collect($transport->recordedRequests)->filter(
            fn ($entry) => $entry->request->getMethod() === 'POST'
                && str_ends_with((string) $entry->request->getUri(), '/child'),
        )->count());
        $this->assertSame(1, collect($transport->recordedRequests)->filter(
            fn ($entry) => $entry->request->getMethod() === 'PUT'
                && str_ends_with((string) $entry->request->getUri(), '/options/202'),
        )->count());
    }

    #[Test]
    public function child_link_no_op_only_rejects_missing_link_without_post(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-LINK-GATE');
        $parentSku = 'MERCHANT-PARENT-SKU';
        $input = $this->configurableInput(
            $workspace,
            $account,
            $product,
            $parentSku,
            new SyncLiveConsequentialWriteGateStub(true),
        );
        $desiredLink = $input->desiredState->childLinks[0];

        $transport = new RecordingConnectorHttpTransport(fn (): ConnectorHttpResult => new ConnectorHttpResult(
            200,
            [],
            '[]',
        ));
        $client = new AdobeProductRemoteStateClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
        );
        $executor = new AdobeConfigurableChildLinkCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            $client,
            new AdobeConfigurableRemoteOptionStateReader,
            new AdobeProductExternalRecordLinkGuard,
        );

        $result = $executor->executeNoOpOnly($input, $desiredLink);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('configurable_child_link_mutation_not_certified', $result->reasonCode);
        $this->assertSame(0, $result->consequentialWriteAttempts);
        $this->assertSame(['GET'], array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function coordinator_preserves_remote_inactive_option_value_and_reaches_lifecycle(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-LIFECYCLE-COORD');
        $parentSku = 'MERCHANT-PARENT-SKU';
        $active = $variants[0];
        $inactive = $variants[1];
        $inactive->update(['is_active' => false]);

        ExternalRecordLink::query()->create($this->merchantConfirmedParentLinkAttributes(
            $workspace, $account->id, $product, $parentSku, '100',
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace, $account->id, $active, $active->sku, '101',
        ));
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace, $account->id, $inactive, $inactive->sku, '102',
        ));

        $semantic = AdobeConfigurableCommandTestFixtures::configurableSemanticResult(
            $product->id,
            [
                ['variant_id' => (string) $active->id, 'sku' => $active->sku, 'color' => 'blue', 'color_index' => '93'],
                ['variant_id' => (string) $inactive->id, 'sku' => $inactive->sku, 'color' => 'red', 'color_index' => '94'],
            ],
        );
        $operations = array_values(array_filter(
            $semantic->operations,
            static function ($operation) use ($inactive): bool {
                if ($operation->operation === 'option_assignment'
                    && ($operation->context['internal_option_key'] ?? null) === 'red'
                ) {
                    return false;
                }

                if (in_array($operation->operation, ['simple_child', 'child_link'], true)
                    && (string) ($operation->context['variant_id'] ?? '') === (string) $inactive->id
                ) {
                    return false;
                }

                return true;
            },
        ));
        $semantic = new AdobeProductExportSemanticResult(
            $semantic->findings,
            $operations,
        );

        $disabled = false;
        $transport = new RecordingConnectorHttpTransport(function ($outbound) use (
            $parentSku,
            $active,
            $inactive,
            &$disabled,
        ): ConnectorHttpResult {
            $request = $outbound->request;
            $method = $request->getMethod();
            $uri = (string) $request->getUri();

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($parentSku))) {
                return new ConnectorHttpResult(200, [], json_encode(
                    AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku, ['id' => 100]),
                    JSON_THROW_ON_ERROR,
                ));
            }

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($active->sku))) {
                return new ConnectorHttpResult(200, [], json_encode([
                    'id' => 101,
                    'sku' => $active->sku,
                    'name' => 'Configurable Product',
                    'attribute_set_id' => 4,
                    'type_id' => 'simple',
                    'status' => 1,
                    'visibility' => 1,
                    'price' => 100.0,
                    'custom_attributes' => [['attribute_code' => 'color', 'value' => 93]],
                    'media_gallery_entries' => [],
                ], JSON_THROW_ON_ERROR));
            }

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($inactive->sku))) {
                if ($method === 'PUT') {
                    $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    $this->assertSame(2, $payload['product']['status'] ?? null);
                    $disabled = true;

                    return new ConnectorHttpResult(200, [], '{}');
                }

                return new ConnectorHttpResult(200, [], json_encode([
                    'id' => 102,
                    'sku' => $inactive->sku,
                    'name' => 'Inactive Child',
                    'attribute_set_id' => 4,
                    'type_id' => 'simple',
                    'status' => $disabled ? 2 : 1,
                    'visibility' => 1,
                    'price' => 100.0,
                    'custom_attributes' => [['attribute_code' => 'color', 'value' => 94]],
                    'media_gallery_entries' => [],
                ], JSON_THROW_ON_ERROR));
            }

            if (str_ends_with($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/options/all')) {
                return new ConnectorHttpResult(200, [], json_encode([[
                    'id' => 201,
                    'attribute_id' => '100',
                    'label' => 'color',
                    'position' => 0,
                    'values' => [['value_index' => 93], ['value_index' => 94]],
                ]], JSON_THROW_ON_ERROR));
            }

            if (str_ends_with($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/children')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    ['sku' => $active->sku],
                    ['sku' => $inactive->sku],
                ], JSON_THROW_ON_ERROR));
            }

            return new ConnectorHttpResult(404, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeConfigurableProductCommandCoordinator::class)->execute(
            $workspace->id,
            $account->id,
            $semantic,
            'UAH',
            $this->metadataFixture(),
            new SyncLiveConsequentialWriteGateStub(true),
        );

        $this->assertSame(SyncLiveOutcome::Synchronized, $result->outcome);
        $this->assertTrue(collect($result->commandEvidence)->contains(
            fn ($entry) => $entry->commandKind === 'configurable_option'
                && $entry->reasonCode === 'configurable_option_remote_values_preserved'
                && $entry->consequentialWriteAttempts === 0,
        ));
        $this->assertTrue(collect($result->commandEvidence)->contains(
            fn ($entry) => $entry->commandKind === 'inactive_child_lifecycle'
                && $entry->reasonCode === 'inactive_linked_child_disabled'
                && $entry->subjectSku === $inactive->sku
                && $entry->consequentialWriteAttempts === 1,
        ));
        $this->assertSame(1, collect($transport->recordedRequests)->filter(
            fn ($entry) => $entry->request->getMethod() === 'PUT',
        )->count());
    }

    #[Test]
    public function trusted_linked_inactive_child_disables_with_one_verified_status_put(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-LIFECYCLE-WRITE');
        $parentSku = 'MERCHANT-PARENT-SKU';
        $inactive = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-LIFECYCLE-WRITE-INACTIVE',
            'is_active' => false,
            'base_price_cache' => 100,
        ]);
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace,
            $account->id,
            $inactive,
            $inactive->sku,
            '103',
        ));
        $input = $this->configurableInput(
            $workspace,
            $account,
            $product,
            $parentSku,
            new SyncLiveConsequentialWriteGateStub(true),
        );

        $disabled = false;
        $transport = new RecordingConnectorHttpTransport(function ($outbound) use (
            $parentSku,
            $inactive,
            &$disabled,
        ): ConnectorHttpResult {
            $request = $outbound->request;
            $uri = (string) $request->getUri();

            if (str_ends_with($uri, '/V1/configurable-products/'.rawurlencode($parentSku).'/children')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    ['sku' => $inactive->sku],
                ], JSON_THROW_ON_ERROR));
            }

            if (str_ends_with($uri, '/V1/products/'.rawurlencode($inactive->sku))) {
                if ($request->getMethod() === 'PUT') {
                    $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    $this->assertSame(2, $payload['product']['status'] ?? null);
                    $disabled = true;

                    return new ConnectorHttpResult(200, [], '{}');
                }

                return new ConnectorHttpResult(200, [], json_encode([
                    'id' => 103,
                    'sku' => $inactive->sku,
                    'name' => 'Inactive Child',
                    'attribute_set_id' => 4,
                    'type_id' => 'simple',
                    'status' => $disabled ? 2 : 1,
                    'visibility' => 1,
                    'price' => 100.0,
                    'custom_attributes' => [],
                    'media_gallery_entries' => [],
                ], JSON_THROW_ON_ERROR));
            }

            return new ConnectorHttpResult(404, [], '{}');
        });

        $normalizer = new AdobeProductRemoteStateNormalizer;
        $client = new AdobeProductRemoteStateClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobeProductRemoteGetClassifier($normalizer),
        );
        $executor = new AdobeConfigurableInactiveLinkedVariantLifecycleExecutor(
            app(AdobePaaSRequestContextFactory::class),
            $client,
            $normalizer,
            new AdobeProductRemoteStateComparator,
            new AdobeProductExternalRecordLinkGuard,
            new AdobeConfigurableRemoteOptionStateReader,
        );

        $results = $executor->execute($input);

        $this->assertCount(1, $results);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $results[0]->appliedStateKnowledge);
        $this->assertSame('inactive_linked_child_disabled', $results[0]->reasonCode);
        $this->assertSame(1, $results[0]->consequentialWriteAttempts);
        $this->assertSame(1, $results[0]->reconciliationGetAttempts);
        $this->assertSame(1, collect($transport->recordedRequests)->filter(
            fn ($entry) => $entry->request->getMethod() === 'PUT',
        )->count());
    }

    #[Test]
    public function inactive_lifecycle_no_op_only_rejects_status_drift_without_put(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-LIFECYCLE-GATE');
        $parentSku = 'MERCHANT-PARENT-SKU';
        $inactive = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-LIFECYCLE-GATE-INACTIVE',
            'is_active' => false,
            'base_price_cache' => 100,
        ]);
        ExternalRecordLink::query()->create($this->merchantConfirmedVariantLinkAttributes(
            $workspace,
            $account->id,
            $inactive,
            $inactive->sku,
            '103',
        ));
        $input = $this->configurableInput(
            $workspace,
            $account,
            $product,
            $parentSku,
            new SyncLiveConsequentialWriteGateStub(true),
        );

        $transport = new RecordingConnectorHttpTransport(fn (): ConnectorHttpResult => new ConnectorHttpResult(
            200,
            [],
            json_encode([
                'id' => 103,
                'sku' => $inactive->sku,
                'name' => 'Inactive Child',
                'attribute_set_id' => 4,
                'type_id' => 'simple',
                'status' => 1,
                'visibility' => 1,
                'price' => 100.0,
                'custom_attributes' => [],
            ], JSON_THROW_ON_ERROR),
        ));
        $normalizer = new AdobeProductRemoteStateNormalizer;
        $client = new AdobeProductRemoteStateClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobeProductRemoteGetClassifier($normalizer),
        );
        $executor = new AdobeConfigurableInactiveLinkedVariantLifecycleExecutor(
            app(AdobePaaSRequestContextFactory::class),
            $client,
            $normalizer,
            new AdobeProductRemoteStateComparator,
            new AdobeProductExternalRecordLinkGuard,
            new AdobeConfigurableRemoteOptionStateReader,
        );

        $results = $executor->executeNoOpOnly($input);

        $this->assertCount(1, $results);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $results[0]->appliedStateKnowledge);
        $this->assertSame('inactive_linked_child_status_mutation_not_certified', $results[0]->reasonCode);
        $this->assertSame(0, $results[0]->consequentialWriteAttempts);
        $this->assertSame(['GET'], array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function option_request_payload_contains_no_per_value_label(): void
    {
        $factory = new AdobeProductCommandRequestFactory(new OAuth1RequestSigner);
        $compiler = new AdobeConfigurableDesiredStateCompiler(new AdobeConfigurableParentSkuGenerator);
        $desiredState = $compiler->compile(
            AdobeConfigurableCommandTestFixtures::configurableSemanticResult(),
            (string) Str::uuid(),
            $this->metadataFixture(),
        );

        $method = new \ReflectionMethod($factory, 'encodeConfigurableOptionPayload');
        $method->setAccessible(true);
        $encoded = $method->invoke($factory, $desiredState->options[0]);
        $payload = json_decode($encoded, true);

        $this->assertArrayHasKey('value_index', $payload['option']['values'][0]);
        $this->assertArrayNotHasKey('label', $payload['option']['values'][0]);
    }

    #[Test]
    public function official_magento_option_response_is_exact_no_op_with_zero_option_put(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-OPTION-NOOP');
        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => $parentSku,
        ]);

        $optionExecutor = new AdobeConfigurableOptionCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductRemoteStateClient(
                app(AdobePaaSRequestContextFactory::class),
                new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
                new RecordingConnectorHttpTransport(fn (): ConnectorHttpResult => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(AdobeConfigurableCommandTestFixtures::remoteOptionsPayload(), JSON_THROW_ON_ERROR),
                )),
                new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
            ),
            new AdobeConfigurableRemoteOptionStateReader,
        );

        $input = $this->configurableInput($workspace, $account, $product);
        $result = $optionExecutor->execute($input, $input->desiredState->options[0]);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame('configurable_option_no_op', $result->reasonCode);
        $this->assertSame(0, $result->consequentialWriteAttempts);
    }

    #[Test]
    public function malformed_option_response_is_fail_closed_with_zero_write(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-MALFORMED-OPTION');

        $optionExecutor = new AdobeConfigurableOptionCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductRemoteStateClient(
                app(AdobePaaSRequestContextFactory::class),
                new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
                new RecordingConnectorHttpTransport(fn (): ConnectorHttpResult => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode([[
                        'id' => 201,
                        'attribute_id' => '100',
                        'label' => 'color',
                        'position' => 0,
                        'values' => [
                            ['value_index' => 'not-a-number'],
                        ],
                    ]], JSON_THROW_ON_ERROR),
                )),
                new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
            ),
            new AdobeConfigurableRemoteOptionStateReader,
        );

        $input = $this->configurableInput($workspace, $account, $product);
        $result = $optionExecutor->execute($input, $input->desiredState->options[0]);

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertSame('configurable_options_get_untrusted', $result->reasonCode);
        $this->assertSame(0, $result->consequentialWriteAttempts);
    }

    #[Test]
    public function malformed_children_response_is_fail_closed_with_zero_child_link_post(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-MALFORMED-CHILDREN');
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

        $transport = new RecordingConnectorHttpTransport(fn (): ConnectorHttpResult => new ConnectorHttpResult(
            200,
            [],
            json_encode([['not_sku' => $variants[0]->sku]], JSON_THROW_ON_ERROR),
        ));

        $childLinkExecutor = new AdobeConfigurableChildLinkCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductRemoteStateClient(
                app(AdobePaaSRequestContextFactory::class),
                new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
                $transport,
                new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
            ),
            new AdobeConfigurableRemoteOptionStateReader,
            new AdobeProductExternalRecordLinkGuard,
        );

        $input = $this->configurableInput($workspace, $account, $product);
        $result = $childLinkExecutor->execute($input, $input->desiredState->childLinks[0]);

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertSame('configurable_children_get_untrusted', $result->reasonCode);
        $this->assertFalse(collect($transport->recordedRequests)->contains(
            fn ($request) => str_contains((string) $request->request->getUri(), '/child')
                && $request->request->getMethod() === 'POST',
        ));
    }

    #[Test]
    public function platform_created_option_pre_link_accepts_stock_magento_pending_values(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-OPTION-PRE-LINK');
        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => $parentSku,
            'trust_origin' => ExternalRecordLinkTrustOrigin::PlatformCreated->value,
            'external_record_discriminator' => '100',
            'established_at' => now(),
        ]);

        $input = $this->configurableInput($workspace, $account, $product);
        $desiredOption = $input->desiredState->options[0];

        $transport = new RecordingConnectorHttpTransport(function () use ($desiredOption): ConnectorHttpResult {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(200, [], '[]'),
                2 => new ConnectorHttpResult(200, [], '201'),
                3 => new ConnectorHttpResult(200, [], json_encode([[
                    'id' => 201,
                    'attribute_id' => (string) $desiredOption->attributeId,
                    'label' => $desiredOption->label,
                    'position' => $desiredOption->position,
                    'values' => [],
                ]], JSON_THROW_ON_ERROR)),
                default => new ConnectorHttpResult(500, [], '{}'),
            };
        });

        $executor = new AdobeConfigurableOptionCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductRemoteStateClient(
                app(AdobePaaSRequestContextFactory::class),
                new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
                $transport,
                new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
            ),
            new AdobeConfigurableRemoteOptionStateReader,
        );

        $result = $executor->executePreLink($input, $desiredOption);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame('configurable_option_post_reconciled_pre_link', $result->reasonCode);
        $this->assertSame(1, $result->consequentialWriteAttempts);
        $this->assertSame(1, $result->reconciliationGetAttempts);
        $this->assertSame(['GET', 'POST', 'GET'], array_map(
            static fn ($entry): string => $entry->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function option_write_transport_failure_reconciles_to_known_applied(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-OPTION-RECON');
        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id),
            'trust_origin' => ExternalRecordLinkTrustOrigin::PlatformCreated->value,
            'external_record_discriminator' => '100',
            'established_at' => now(),
        ]);

        $transport = new RecordingConnectorHttpTransport(function (): ConnectorHttpResult {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(200, [], '[]'),
                2 => new ConnectorHttpResult(500, [], '{}'),
                3 => new ConnectorHttpResult(200, [], json_encode(AdobeConfigurableCommandTestFixtures::remoteOptionsPayload(), JSON_THROW_ON_ERROR)),
                default => new ConnectorHttpResult(500, [], '{}'),
            };
        });

        $optionExecutor = new AdobeConfigurableOptionCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductRemoteStateClient(
                app(AdobePaaSRequestContextFactory::class),
                new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
                $transport,
                new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
            ),
            new AdobeConfigurableRemoteOptionStateReader,
        );

        $input = $this->configurableInput($workspace, $account, $product);
        $result = $optionExecutor->execute($input, $input->desiredState->options[0]);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame(1, $result->reconciliationGetAttempts);
        $this->assertSame(1, $result->consequentialWriteAttempts);
        $this->assertSame(3, $transport->sendCount);
    }

    #[Test]
    public function child_link_write_transport_failure_reconciles_to_known_applied(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-LINK-RECON');
        $childSku = $variants[0]->sku;

        $transport = new RecordingConnectorHttpTransport(function () use ($childSku): ConnectorHttpResult {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(200, [], '[]'),
                2 => new ConnectorHttpResult(500, [], '{}'),
                3 => new ConnectorHttpResult(200, [], json_encode([['sku' => $childSku]], JSON_THROW_ON_ERROR)),
                default => new ConnectorHttpResult(500, [], '{}'),
            };
        });

        $childLinkExecutor = new AdobeConfigurableChildLinkCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductRemoteStateClient(
                app(AdobePaaSRequestContextFactory::class),
                new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
                $transport,
                new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
            ),
            new AdobeConfigurableRemoteOptionStateReader,
            new AdobeProductExternalRecordLinkGuard,
        );

        $semantic = AdobeConfigurableCommandTestFixtures::configurableSemanticResult(
            $product->id,
            [
                ['variant_id' => (string) $variants[0]->id, 'sku' => $variants[0]->sku, 'color' => 'blue', 'color_index' => '93'],
                ['variant_id' => (string) $variants[1]->id, 'sku' => $variants[1]->sku, 'color' => 'red', 'color_index' => '94'],
            ],
        );
        $compiler = new AdobeConfigurableDesiredStateCompiler(new AdobeConfigurableParentSkuGenerator);
        $desired = $compiler->compile($semantic, $workspace->id, $this->metadataFixture());
        $input = new AdobeConfigurableCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: $semantic,
            desiredState: $desired,
            adobeBaseCurrency: 'UAH',
            metadata: $this->metadataFixture(),
        );

        $result = $childLinkExecutor->execute($input, $input->desiredState->childLinks[0]);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame(1, $result->reconciliationGetAttempts);
        $this->assertSame(1, $result->consequentialWriteAttempts);
        $this->assertSame(3, $transport->sendCount);
    }

    #[Test]
    public function legacy_child_links_fail_closed_before_inactive_lifecycle(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variants] = $this->createConfigurableProduct($workspace, 'CFG-LINK-PARTIAL');
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

        $inactiveVariant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $product->sku.'-INACTIVE',
            'is_active' => false,
        ]);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $inactiveVariant->id,
            'external_identifier' => $inactiveVariant->sku,
        ]);

        [$coordinator, $transport] = $this->coordinatorStack();

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
            new SyncLiveConsequentialWriteGateStub(false),
        );

        $this->assertSame(SyncLiveOutcome::NotApplied, $result->outcome);
        $this->assertFalse(collect($result->commandEvidence)->contains(
            fn ($entry) => $entry->commandKind === 'inactive_child_lifecycle',
        ));
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function ambiguous_parent_links_with_simple_semantic_are_ambiguous_with_zero_http(): void
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
        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'second-parent-sku',
        ]);

        $transport = new RecordingConnectorHttpTransport(fn (): ConnectorHttpResult => new ConnectorHttpResult(500, [], '{}'));
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $capability = app(AdobeProductExportLiveCapability::class);
        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $this->simpleSnapshot(),
        )[0];

        $result = $capability->executeProduct(
            $aggregate,
            $this->simpleSnapshot(),
            new AdobeProductExportLiveRunContext(
                workspaceId: $workspace->id,
                connectorAccountId: $account->id,
                metadata: $this->metadataFixture(),
                adobeBaseCurrency: 'UAH',
            ),
            new SyncLiveConsequentialWriteGateStub(true),
        );

        $this->assertSame(SyncLiveOutcome::Ambiguous, $result->outcome);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === 'ambiguous_configurable_parent_identity_links',
        ));
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function ambiguous_parent_links_with_configurable_semantic_are_ambiguous_with_zero_http(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product] = $this->createConfigurableProduct($workspace, 'CFG-AMBIG-PARENT');
        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => $parentSku,
        ]);
        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'second-parent-sku',
        ]);

        $transport = new RecordingConnectorHttpTransport(fn (): ConnectorHttpResult => new ConnectorHttpResult(500, [], '{}'));
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $capability = app(AdobeProductExportLiveCapability::class);
        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $this->configurableSnapshot(),
        )[0];

        $result = $capability->executeProduct(
            $aggregate,
            $this->configurableSnapshot(),
            new AdobeProductExportLiveRunContext(
                workspaceId: $workspace->id,
                connectorAccountId: $account->id,
                metadata: $this->metadataFixture(),
                adobeBaseCurrency: 'UAH',
            ),
            new SyncLiveConsequentialWriteGateStub(true),
        );

        $this->assertSame(SyncLiveOutcome::Ambiguous, $result->outcome);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === 'ambiguous_configurable_parent_identity_links',
        ));
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function configurable_option_label_prefers_current_adobe_default_frontend_label(): void
    {
        $compiler = new AdobeConfigurableDesiredStateCompiler(new AdobeConfigurableParentSkuGenerator);
        $metadata = new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: 4,
            attributeSets: [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            attributes: [
                'color' => new AdobeAttributeMetadata(
                    attributeId: 100,
                    code: 'color',
                    frontendInput: 'select',
                    scope: 'global',
                    options: ['93' => 'Blue', '94' => 'Red'],
                    defaultFrontendLabel: 'Color',
                ),
            ],
        );

        $desired = $compiler->compile(
            AdobeConfigurableCommandTestFixtures::configurableSemanticResult(),
            (string) Str::uuid(),
            $metadata,
        );

        $this->assertSame('Color', $desired->options[0]->label);
    }

    #[Test]
    public function configurable_option_label_falls_back_to_external_field_key_when_adobe_label_missing(): void
    {
        $compiler = new AdobeConfigurableDesiredStateCompiler(new AdobeConfigurableParentSkuGenerator);
        $desired = $compiler->compile(
            AdobeConfigurableCommandTestFixtures::configurableSemanticResult(),
            (string) Str::uuid(),
            $this->metadataFixture(),
        );

        $this->assertSame('color', $desired->options[0]->label);
    }

    #[Test]
    public function required_configurable_dimension_bootstrap_is_deterministic_and_parent_starts_disabled(): void
    {
        $metadata = new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: 4,
            attributeSets: [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            attributes: [
                'color' => new AdobeAttributeMetadata(
                    attributeId: 100,
                    code: 'color',
                    frontendInput: 'select',
                    scope: 'global',
                    options: ['93' => 'Blue', '94' => 'Red'],
                    isRequired: true,
                    applyTo: ['configurable'],
                ),
            ],
        );

        $desired = (new AdobeConfigurableDesiredStateCompiler(new AdobeConfigurableParentSkuGenerator))->compile(
            AdobeConfigurableCommandTestFixtures::configurableSemanticResult(),
            (string) Str::uuid(),
            $metadata,
        );

        $this->assertSame(1, $desired->parent->status);
        $this->assertArrayNotHasKey('color', $desired->parent->customAttributes);
        $this->assertSame(2, $desired->createParent?->status);
        $this->assertSame(93, $desired->createParent?->customAttributes['color']);
        $this->assertSame(['color'], $desired->bootstrapAttributeCodes);
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
                $linkGuard,
                new AdobeProductStockSimpleWriteExecutor(
                    app(AdobePaaSRequestContextFactory::class),
                    $client,
                    $comparator,
                ),
            ),
            new AdobeConfigurableParentCommandExecutor(
                $linkGuard,
                app(AdobePaaSRequestContextFactory::class),
                $client,
                $comparator,
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
                $linkGuard,
            ),
            new AdobeConfigurableInactiveLinkedVariantLifecycleExecutor(
                app(AdobePaaSRequestContextFactory::class),
                $client,
                $normalizer,
                $comparator,
                $linkGuard,
                $optionReader,
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
        $transport = new RecordingConnectorHttpTransport(
            $responder ?? fn (): ConnectorHttpResult => new ConnectorHttpResult(500, [], '{}'),
        );
        $normalizer = new AdobeProductRemoteStateNormalizer;
        $client = new AdobeProductRemoteStateClient(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
            $transport,
            new AdobeProductRemoteGetClassifier($normalizer),
        );

        return [
            new AdobeConfigurableParentCommandExecutor(
                new AdobeProductExternalRecordLinkGuard,
                app(AdobePaaSRequestContextFactory::class),
                $client,
                new AdobeProductRemoteStateComparator,
            ),
            $transport,
        ];
    }

    private function configurableInput(
        Workspace $workspace,
        ConnectorAccount $account,
        Product $product,
        ?string $trustedExistingParentSku = null,
        ?SyncLiveConsequentialWriteGateStub $consequentialWriteGate = null,
    ): AdobeConfigurableCommandInput {
        $semantic = AdobeConfigurableCommandTestFixtures::configurableSemanticResult($product->id);
        $compiler = new AdobeConfigurableDesiredStateCompiler(new AdobeConfigurableParentSkuGenerator);
        $desired = $compiler->compile(
            $semantic,
            $workspace->id,
            $this->metadataFixture(),
            $trustedExistingParentSku,
        );

        return new AdobeConfigurableCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: $semantic,
            desiredState: $desired,
            adobeBaseCurrency: 'UAH',
            metadata: $this->metadataFixture(),
            consequentialWriteGate: $consequentialWriteGate,
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
    private function configurableSnapshot(): array
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
                [
                    'field_binding_id' => $this->productVariantBinding('color')->id,
                    'external_field_key' => 'color',
                    'option_mappings' => [
                        ['internal_option_key' => 'blue', 'external_option_value' => '93'],
                        ['internal_option_key' => 'red', 'external_option_value' => '94'],
                    ],
                ],
            ],
            'connector_execution_configuration' => ['attribute_set_id' => 4],
        ];
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
