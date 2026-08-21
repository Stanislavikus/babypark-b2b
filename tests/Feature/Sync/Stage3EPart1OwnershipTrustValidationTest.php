<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncDataDomain;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use App\Support\Connectors\AdobePaaS\AdobeAttributeMetadata;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectorAdapter;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableCommandInput;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableParentCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableParentSkuGenerator;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductCommandRequestFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductCreateOwnershipEvidence;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkGuard;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersistence;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersister;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductObservedState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductOwnershipTrustPolicy;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteGetClassification;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteGetClassifier;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateClient;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateComparator;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateNormalizer;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandInput;
use App\Support\Connectors\AdobePaaS\Command\ConservativeAdobeProductOwnershipTrustPolicy;
use App\Support\Connectors\AdobePaaS\Validation\AdobeStage3EMediaValidationMatrix;
use App\Support\Connectors\AdobePaaS\Validation\AdobeStage3EValidationCommand;
use App\Support\Connectors\AdobePaaS\Validation\AdobeStage3EValidationEnvironment;
use App\Support\Connectors\AdobePaaS\Validation\AdobeStage3EValidationEvidenceSink;
use App\Support\Connectors\AdobePaaS\Validation\AdobeStage3EValidationGuard;
use App\Support\Connectors\AdobePaaS\Validation\AdobeStage3EValidationRunner;
use App\Support\Connectors\AdobePaaS\Validation\AdobeStage3EValidationTransportArmKey;
use App\Support\Connectors\AdobePaaS\Validation\AdobeStage3EValidationTransportDecorator;
use App\Support\Connectors\AdobePaaS\Validation\AdobeStage3EValidationTransportFaultShape;
use App\Support\Connectors\ConnectorSyncSupportResolver;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use App\Support\Connectors\Transport\TransportFailureReason;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeConfigurableCommandTestFixtures;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeProductCommandTestFixtures;
use Tests\Support\Connectors\AdobePaaS\Command\ThrowingAdobeProductExternalRecordLinkPersister;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class Stage3EPart1OwnershipTrustValidationTest extends TestCase
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
    public function production_binding_resolves_conservative_adobe_product_ownership_trust_policy(): void
    {
        $policy = app(AdobeProductOwnershipTrustPolicy::class);

        $this->assertInstanceOf(ConservativeAdobeProductOwnershipTrustPolicy::class, $policy);
    }

    #[Test]
    public function conservative_policy_approves_only_definitive_create_provenance(): void
    {
        $policy = new ConservativeAdobeProductOwnershipTrustPolicy;
        $desired = (new AdobeProductDesiredStateCompiler)->compileFromSemanticResult(
            AdobeProductCommandTestFixtures::semanticResult(),
        );
        $observed = new AdobeProductObservedState(
            sku: $desired->sku,
            name: $desired->name,
            attributeSetId: $desired->attributeSetId,
            typeId: 'simple',
            status: $desired->status,
            visibility: $desired->visibility,
            price: $desired->price,
            customAttributes: $desired->customAttributes,
        );

        $approved = $policy->canPersistNewLink(
            $desired,
            $observed,
            AdobeProductCreateOwnershipEvidence::definitiveCreate(
                AdobeProductRemoteGetClassification::TrustedKnownMissing,
            ),
        );

        $deniedPreWriteFound = $policy->canPersistNewLink(
            $desired,
            $observed,
            AdobeProductCreateOwnershipEvidence::definitiveCreate(
                AdobeProductRemoteGetClassification::Found,
            ),
        );

        $deniedInconclusive = $policy->canPersistNewLink(
            $desired,
            $observed,
            AdobeProductCreateOwnershipEvidence::inconclusive(
                AdobeProductRemoteGetClassification::TrustedKnownMissing,
            ),
        );

        $this->assertTrue($approved);
        $this->assertFalse($deniedPreWriteFound);
        $this->assertFalse($deniedInconclusive);
    }

    #[Test]
    public function clean_definitive_simple_create_persists_exactly_one_variant_erl(): void
    {
        [$executor, $transport] = $this->productionSimpleExecutor($this->definitiveCreateResponder('SKU-TEST-1'));

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace, 'SKU-TEST-1');

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
                'sku' => 'SKU-TEST-1',
            ]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertTrue($result->evidence->externalRecordLinkPersisted);
        $this->assertSame(['GET', 'POST', 'GET'], $this->requestMethods($transport));
        $this->assertSame(1, ExternalRecordLink::query()->count());
    }

    #[Test]
    public function trusted_link_rerun_performs_get_only_without_duplicate_post(): void
    {
        [$executor, $transport] = $this->productionSimpleExecutor(fn () => new ConnectorHttpResult(
            200,
            [],
            json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(), JSON_THROW_ON_ERROR),
        ));

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace, 'SKU-TEST-1');

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'SKU-TEST-1',
        ]);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
                'sku' => 'SKU-TEST-1',
            ]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame(['GET'], $this->requestMethods($transport));
    }

    #[Test]
    public function pre_existing_remote_without_trusted_link_is_never_adopted(): void
    {
        [$executor, $transport] = $this->productionSimpleExecutor(fn () => new ConnectorHttpResult(
            200,
            [],
            json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(), JSON_THROW_ON_ERROR),
        ));

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace, 'SKU-TEST-1');

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
                'sku' => 'SKU-TEST-1',
            ]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('remote_found_without_trusted_link', $result->evidence->reasonCode);
        $this->assertSame(['GET'], $this->requestMethods($transport));
        $this->assertSame(0, ExternalRecordLink::query()->count());
    }

    #[Test]
    public function transport_ambiguous_reconciliation_match_does_not_create_erl(): void
    {
        [$executor, $transport] = $this->productionSimpleExecutor(function (): ConnectorHttpResult {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(
                    404,
                    [],
                    AdobeProductCommandTestFixtures::trustedMissing404Body('SKU-TEST-1'),
                ),
                2 => throw new ConnectorTransportException(TransportFailureReason::Timeout),
                3 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(), JSON_THROW_ON_ERROR),
                ),
                default => new ConnectorHttpResult(500, [], '{}'),
            };
        });

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace, 'SKU-TEST-1');

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
                'sku' => 'SKU-TEST-1',
            ]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertStringContainsString('ownership_not_proven', $result->evidence->reasonCode);
        $this->assertSame(0, ExternalRecordLink::query()->count());
    }

    #[Test]
    public function non_2xx_reconciliation_match_does_not_create_erl(): void
    {
        [$executor, $transport] = $this->productionSimpleExecutor(function (): ConnectorHttpResult {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(
                    404,
                    [],
                    AdobeProductCommandTestFixtures::trustedMissing404Body('SKU-TEST-1'),
                ),
                2 => new ConnectorHttpResult(500, [], '{"message":"post_failed"}'),
                3 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(), JSON_THROW_ON_ERROR),
                ),
                default => new ConnectorHttpResult(500, [], '{}'),
            };
        });

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace, 'SKU-TEST-1');

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
                'sku' => 'SKU-TEST-1',
            ]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertStringContainsString('ownership_not_proven', $result->evidence->reasonCode);
        $this->assertSame(0, ExternalRecordLink::query()->count());
    }

    #[Test]
    public function inconclusive_body_reconciliation_match_does_not_create_erl(): void
    {
        [$executor, $transport] = $this->productionSimpleExecutor(function (): ConnectorHttpResult {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(
                    404,
                    [],
                    AdobeProductCommandTestFixtures::trustedMissing404Body('SKU-TEST-1'),
                ),
                2 => new ConnectorHttpResult(200, [], '{"message":"no_sku_in_body"}'),
                3 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(), JSON_THROW_ON_ERROR),
                ),
                default => new ConnectorHttpResult(500, [], '{}'),
            };
        });

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace, 'SKU-TEST-1');

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
                'sku' => 'SKU-TEST-1',
            ]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertStringContainsString('ownership_not_proven', $result->evidence->reasonCode);
        $this->assertSame(0, ExternalRecordLink::query()->count());
    }

    #[Test]
    public function erl_persistence_failure_after_remote_application_returns_unknown_without_external_retry(): void
    {
        $persister = new ThrowingAdobeProductExternalRecordLinkPersister;

        [$executor, $transport] = $this->productionSimpleExecutor(
            $this->definitiveCreateResponder('SKU-TEST-1'),
            $persister,
        );

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace, 'SKU-TEST-1');

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
                'sku' => 'SKU-TEST-1',
            ]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertSame('no_link_post_reconciled_link_persistence_failed', $result->evidence->reasonCode);
        $this->assertSame(['GET', 'POST', 'GET'], $this->requestMethods($transport));
    }

    #[Test]
    public function definitive_parent_create_persists_product_scoped_erl(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $product = $this->createConfigurableProductRecord($workspace, 'CFG-PARENT');
        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);

        [$executor, $transport] = $this->productionParentExecutor(
            $this->definitiveParentCreateResponder($parentSku),
        );

        $result = $executor->execute($this->parentInput($workspace, $account, $product));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertTrue($result->externalRecordLinkPersisted);
        $this->assertSame(1, ExternalRecordLink::query()->where('product_id', $product->id)->count());
        $this->assertSame(['GET', 'POST', 'GET'], $this->requestMethods($transport));
    }

    #[Test]
    public function pre_existing_parent_without_trusted_link_is_never_adopted(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $product = $this->createConfigurableProductRecord($workspace, 'CFG-PARENT');
        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);

        [$executor, $transport] = $this->productionParentExecutor(fn () => new ConnectorHttpResult(
            200,
            [],
            json_encode(AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku), JSON_THROW_ON_ERROR),
        ));

        $result = $executor->execute($this->parentInput($workspace, $account, $product));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('remote_found_without_trusted_parent_link', $result->reasonCode);
        $this->assertSame(['GET'], $this->requestMethods($transport));
        $this->assertSame(0, ExternalRecordLink::query()->where('product_id', $product->id)->count());
    }

    #[Test]
    public function inconclusive_parent_reconciliation_never_creates_erl(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $product = $this->createConfigurableProductRecord($workspace, 'CFG-PARENT');
        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);

        [$executor] = $this->productionParentExecutor(function () use ($parentSku): ConnectorHttpResult {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(
                    404,
                    [],
                    AdobeProductCommandTestFixtures::trustedMissing404Body($parentSku),
                ),
                2 => new ConnectorHttpResult(500, [], '{"message":"post_failed"}'),
                3 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku), JSON_THROW_ON_ERROR),
                ),
                default => new ConnectorHttpResult(500, [], '{}'),
            };
        });

        $result = $executor->execute($this->parentInput($workspace, $account, $product));

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertStringContainsString('ownership_not_proven', $result->reasonCode);
        $this->assertSame(0, ExternalRecordLink::query()->where('product_id', $product->id)->count());
    }

    #[Test]
    public function parent_rerun_with_trusted_link_does_not_duplicate_parent_post(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $product = $this->createConfigurableProductRecord($workspace, 'CFG-PARENT');
        $parentSku = (new AdobeConfigurableParentSkuGenerator)->generate($workspace->id, $product->id);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => $parentSku,
        ]);

        [$executor, $transport] = $this->productionParentExecutor(fn () => new ConnectorHttpResult(
            200,
            [],
            json_encode(AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku), JSON_THROW_ON_ERROR),
        ));

        $result = $executor->execute($this->parentInput($workspace, $account, $product));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame(['GET'], $this->requestMethods($transport));
    }

    #[Test]
    public function validation_command_is_absent_outside_stage3e_validation_environment(): void
    {
        $this->assertFalse(AdobeStage3EValidationEnvironment::isActive());
        $this->assertArrayNotHasKey(
            'adobe:stage3e-validate',
            app(Kernel::class)->all(),
        );
    }

    #[Test]
    public function validation_command_registration_is_gated_in_console_routes(): void
    {
        $contents = file_get_contents(base_path('routes/console.php'));

        $this->assertIsString($contents);
        $this->assertStringContainsString('stage3e-validation', $contents);
        $this->assertStringContainsString('AdobeStage3EValidationCommand', $contents);
    }

    #[Test]
    public function validation_command_signature_has_no_credential_parameters(): void
    {
        $command = new AdobeStage3EValidationCommand;

        $this->assertStringNotContainsString('token', strtolower($command->getDefinition()->getSynopsis()));
        $this->assertStringNotContainsString('password', strtolower($command->getDefinition()->getSynopsis()));
        $this->assertStringNotContainsString('credential', strtolower($command->getDefinition()->getSynopsis()));
        $this->assertStringNotContainsString('secret', strtolower($command->getDefinition()->getSynopsis()));
    }

    #[Test]
    public function validation_guard_fails_closed_on_host_mismatch(): void
    {
        $this->app['env'] = AdobeStage3EValidationEnvironment::ENVIRONMENT_NAME;
        config(['adobe_stage3e_validation.allow_host' => 'validation.example.com']);

        $account = $this->createConnectorAccount(null, [
            'base_url' => 'https://validation.example.com',
        ]);

        $result = app(AdobeStage3EValidationGuard::class)->evaluate(
            $account,
            'other.example.com',
            false,
        );

        $this->assertFalse($result->passed);
        $this->assertContains('expect_host_mismatch', $result->failureCodes);
    }

    #[Test]
    public function validation_guard_refuses_ordinary_workspace_products(): void
    {
        $this->app['env'] = AdobeStage3EValidationEnvironment::ENVIRONMENT_NAME;
        config(['adobe_stage3e_validation.allow_host' => 'shop.example.com']);

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $this->createProductVariant($workspace, 'ORDINARY-SKU');

        $result = app(AdobeStage3EValidationGuard::class)->evaluate(
            $account,
            'shop.example.com',
            false,
        );

        $this->assertFalse($result->passed);
        $this->assertContains('workspace_contains_non_validation_skus', $result->failureCodes);
    }

    #[Test]
    public function validation_transport_decorator_is_not_globally_bound(): void
    {
        $this->assertFalse($this->app->bound(AdobeStage3EValidationTransportDecorator::class));
    }

    #[Test]
    public function validation_transport_decorator_records_sanitized_evidence_without_secrets(): void
    {
        $this->app['env'] = AdobeStage3EValidationEnvironment::ENVIRONMENT_NAME;

        $delegate = new RecordingConnectorHttpTransport(fn (): ConnectorHttpResult => new ConnectorHttpResult(
            200,
            ['Authorization' => 'Bearer secret-token'],
            '{"sku":"B2BVAL-1","oauth":"hidden"}',
        ));

        $sink = new AdobeStage3EValidationEvidenceSink;
        $decorator = app(AdobeStage3EValidationRunner::class)->createValidationTransportDecorator($delegate, $sink);
        $decorator->armFault(
            new AdobeStage3EValidationTransportArmKey('POST', 'product', 'B2BVAL-1'),
            AdobeStage3EValidationTransportFaultShape::TransportUnknown,
        );

        $request = new ConnectorOutboundRequest(
            new Request('POST', 'https://validation.example.com/rest/V1/products/B2BVAL-1'),
            new ConnectorTransportLimits(5.0, 30.0, 1048576),
        );

        try {
            $decorator->send($request);
            $this->fail('Expected synthetic non-2xx fault to be returned to caller.');
        } catch (ConnectorTransportException) {
            // transport-unknown fault shape throws
        }

        $encoded = json_encode($sink->sanitizedEntries(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Authorization', $encoded);
        $this->assertStringNotContainsString('secret-token', $encoded);
        $this->assertStringNotContainsString('oauth', $encoded);
        $this->assertArrayHasKey('response_body_sha256', $sink->sanitizedEntries()[0]);
    }

    #[Test]
    public function media_validation_matrix_encodes_future_real_target_contract(): void
    {
        $classes = AdobeStage3EMediaValidationMatrix::supportedClasses();

        $this->assertNotEmpty($classes);
        $this->assertTrue(AdobeStage3EMediaValidationMatrix::requiresSecondFullExecution());
        $this->assertSame(
            'stop_truth_flip_and_return_to_reconciliation_redesign',
            AdobeStage3EMediaValidationMatrix::byteIdentityFailureAction(),
        );
    }

    #[Test]
    public function adobe_products_export_live_support_remains_false(): void
    {
        $account = $this->createConnectorAccount();
        $resolver = app(ConnectorSyncSupportResolver::class);

        $this->assertFalse((new AdobePaaSConnectorAdapter)->supports(
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
            SyncRunMode::Live,
        ));
        $this->assertFalse($resolver->supports(
            $account,
            SyncDataDomain::Products,
            SyncSemanticOperation::Export,
            SyncRunMode::Live,
        ));
    }

    /**
     * @return array{0: AdobeProductSimpleCommandExecutor, 1: RecordingConnectorHttpTransport}
     */
    private function productionSimpleExecutor(
        \Closure $responder,
        ?AdobeProductExternalRecordLinkPersistence $persister = null,
    ): array {
        $transport = new RecordingConnectorHttpTransport($responder);
        $linkGuard = new AdobeProductExternalRecordLinkGuard;

        $executor = new AdobeProductSimpleCommandExecutor(
            new AdobeProductDesiredStateCompiler,
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductRemoteStateClient(
                app(AdobePaaSRequestContextFactory::class),
                new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
                $transport,
                new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
            ),
            new AdobeProductRemoteStateComparator,
            $linkGuard,
            $persister ?? new AdobeProductExternalRecordLinkPersister($linkGuard),
            app(AdobeProductOwnershipTrustPolicy::class),
        );

        return [$executor, $transport];
    }

    /**
     * @return array{0: AdobeConfigurableParentCommandExecutor, 1: RecordingConnectorHttpTransport}
     */
    private function productionParentExecutor(\Closure $responder): array
    {
        $transport = new RecordingConnectorHttpTransport($responder);
        $linkGuard = new AdobeProductExternalRecordLinkGuard;

        $executor = new AdobeConfigurableParentCommandExecutor(
            app(AdobePaaSRequestContextFactory::class),
            new AdobeProductRemoteStateClient(
                app(AdobePaaSRequestContextFactory::class),
                new AdobeProductCommandRequestFactory(new OAuth1RequestSigner),
                $transport,
                new AdobeProductRemoteGetClassifier(new AdobeProductRemoteStateNormalizer),
            ),
            new AdobeProductRemoteStateComparator,
            $linkGuard,
            new AdobeProductExternalRecordLinkPersister($linkGuard),
            app(AdobeProductOwnershipTrustPolicy::class),
        );

        return [$executor, $transport];
    }

    private function definitiveCreateResponder(string $sku): \Closure
    {
        return function (): ConnectorHttpResult {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(
                    404,
                    [],
                    AdobeProductCommandTestFixtures::trustedMissing404Body('SKU-TEST-1'),
                ),
                2 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(['product' => AdobeProductCommandTestFixtures::remoteProductPayload(['sku' => 'SKU-TEST-1'])], JSON_THROW_ON_ERROR),
                ),
                3 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(['sku' => 'SKU-TEST-1']), JSON_THROW_ON_ERROR),
                ),
                default => new ConnectorHttpResult(500, [], '{}'),
            };
        };
    }

    private function definitiveParentCreateResponder(string $parentSku): \Closure
    {
        return function () use ($parentSku): ConnectorHttpResult {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(
                    404,
                    [],
                    AdobeProductCommandTestFixtures::trustedMissing404Body($parentSku),
                ),
                2 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(['product' => AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku)], JSON_THROW_ON_ERROR),
                ),
                3 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(AdobeConfigurableCommandTestFixtures::remoteParentPayload($parentSku), JSON_THROW_ON_ERROR),
                ),
                default => new ConnectorHttpResult(500, [], '{}'),
            };
        };
    }

    private function parentInput(Workspace $workspace, ConnectorAccount $account, Product $product): AdobeConfigurableCommandInput
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

    private function createConfigurableProductRecord(Workspace $workspace, string $sku): Product
    {
        return Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'name' => 'Configurable Product',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: Product, 1: ProductVariant}
     */
    private function createProductVariant(Workspace $workspace, string $variantSku): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'PROD-'.Str::random(6),
            'name' => 'Product',
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $variantSku,
            'is_active' => true,
            'base_price_cache' => 100,
        ]);

        return [$product, $variant];
    }

    /**
     * @return list<string>
     */
    private function requestMethods(RecordingConnectorHttpTransport $transport): array
    {
        return array_map(
            static fn ($request) => $request->request->getMethod(),
            $transport->recordedRequests,
        );
    }
}
