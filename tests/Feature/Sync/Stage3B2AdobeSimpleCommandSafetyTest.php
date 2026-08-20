<?php

namespace Tests\Feature\Sync;

use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductCommandRequestFactory;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkGuard;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersistence;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersister;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductObservedState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductOwnershipTrustPolicy;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteGetClassifier;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateClient;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateComparator;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateNormalizer;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandInput;
use App\Support\Connectors\AdobePaaS\Command\ConservativeAdobeProductOwnershipTrustPolicy;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeProductCommandTestFixtures;
use Tests\Support\Connectors\AdobePaaS\Command\ThrowingAdobeProductExternalRecordLinkPersister;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class Stage3B2AdobeSimpleCommandSafetyTest extends TestCase
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
    public function blocking_semantic_result_performs_zero_consequential_requests(): void
    {
        [$executor, $transport] = $this->executor();

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $this->defaultWorkspace()->id,
            connectorAccountId: $this->createConnectorAccount()->id,
            semanticResult: AdobeProductCommandTestFixtures::blockingSemanticResult(),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function currency_missing_or_mismatch_performs_zero_post_or_put(): void
    {
        [$executor, $transport] = $this->executor(responder: fn () => new ConnectorHttpResult(
            404,
            [],
            AdobeProductCommandTestFixtures::trustedMissing404Body('SKU-TEST-1'),
        ));

        $account = $this->createConnectorAccount();
        $semantic = AdobeProductCommandTestFixtures::semanticResult();

        $missingCurrency = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $this->defaultWorkspace()->id,
            connectorAccountId: $account->id,
            semanticResult: $semantic,
            adobeBaseCurrency: null,
        ));

        $transport->sendCount = 0;

        $mismatchCurrency = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $this->defaultWorkspace()->id,
            connectorAccountId: $account->id,
            semanticResult: $semantic,
            adobeBaseCurrency: 'USD',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $missingCurrency->appliedStateKnowledge);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $mismatchCurrency->appliedStateKnowledge);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function no_link_and_remote_found_is_collision_with_zero_writes(): void
    {
        [$executor, $transport] = $this->executor(responder: fn () => new ConnectorHttpResult(
            200,
            [],
            json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(), JSON_THROW_ON_ERROR),
        ));

        $result = $executor->execute($this->defaultInput());

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('remote_found_without_trusted_link', $result->evidence->reasonCode);
        $this->assertSame(1, $transport->sendCount);
        $this->assertSame('GET', $transport->recordedRequests[0]->request->getMethod());
    }

    #[Test]
    public function cross_subject_same_adobe_sku_erl_is_collision_with_zero_writes(): void
    {
        [$executor, $transport] = $this->executor();
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variant] = $this->createProductVariant($workspace);
        [$_, $subjectVariant] = $this->createProductVariant($workspace);

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
                'variant_id' => $subjectVariant->id,
            ]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('external_record_link_collision', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function no_link_and_trusted_missing_attempts_at_most_one_post(): void
    {
        [$executor, $transport] = $this->executor(responder: function (): ConnectorHttpResult {
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
                    json_encode(['product' => AdobeProductCommandTestFixtures::remoteProductPayload()], JSON_THROW_ON_ERROR),
                ),
                default => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(), JSON_THROW_ON_ERROR),
                ),
            };
        });

        $result = $executor->execute($this->defaultInput());

        $methods = array_map(
            static fn ($request) => $request->request->getMethod(),
            $transport->recordedRequests,
        );

        $this->assertSame(['GET', 'POST', 'GET'], $methods);
        $this->assertSame(1, $result->evidence->consequentialWriteAttempts);
        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertFalse($result->evidence->externalRecordLinkPersisted);
    }

    #[Test]
    public function post_transport_ambiguity_reconciles_with_get_and_never_second_post(): void
    {
        [$executor, $transport] = $this->executor(responder: function (): ConnectorHttpResult {
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
                default => new ConnectorHttpResult(500, [], '{"message":"unexpected"}'),
            };
        });

        $result = $executor->execute($this->defaultInput());

        $methods = array_map(
            static fn ($request) => $request->request->getMethod(),
            $transport->recordedRequests,
        );

        $this->assertSame(['GET', 'POST', 'GET'], $methods);
        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertSame('no_link_post_ambiguous_ownership_not_proven', $result->evidence->reasonCode);
    }

    #[Test]
    public function post_inconclusive_success_body_does_not_fabricate_erl(): void
    {
        [$executor, $transport] = $this->executor(responder: function (): ConnectorHttpResult {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(
                    404,
                    [],
                    AdobeProductCommandTestFixtures::trustedMissing404Body('SKU-TEST-1'),
                ),
                2 => new ConnectorHttpResult(200, [], '{"status":"ok"}'),
                3 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(), JSON_THROW_ON_ERROR),
                ),
                default => new ConnectorHttpResult(500, [], '{}'),
            };
        });

        $result = $executor->execute($this->defaultInput());

        $this->assertSame(0, ExternalRecordLink::query()->count());
        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertFalse($result->evidence->externalRecordLinkPersisted);
        $this->assertSame(['GET', 'POST', 'GET'], array_map(
            static fn ($request) => $request->request->getMethod(),
            $transport->recordedRequests,
        ));
    }

    #[Test]
    public function existing_trusted_link_with_equal_remote_state_performs_no_put(): void
    {
        [$executor, $transport] = $this->executor(responder: fn () => new ConnectorHttpResult(
            200,
            [],
            json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(), JSON_THROW_ON_ERROR),
        ));

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'SKU-TEST-1',
        ]);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame(1, $transport->sendCount);
        $this->assertSame('GET', $transport->recordedRequests[0]->request->getMethod());
    }

    #[Test]
    public function existing_trusted_link_with_different_state_sends_exactly_one_put(): void
    {
        [$executor, $transport] = $this->executor(responder: function (): ConnectorHttpResult {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(['name' => 'Old Name']), JSON_THROW_ON_ERROR),
                ),
                2 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(['product' => AdobeProductCommandTestFixtures::remoteProductPayload()], JSON_THROW_ON_ERROR),
                ),
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
        [$_, $variant] = $this->createProductVariant($workspace);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'SKU-TEST-1',
        ]);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
            adobeBaseCurrency: 'UAH',
        ));

        $methods = array_map(
            static fn ($request) => $request->request->getMethod(),
            $transport->recordedRequests,
        );

        $this->assertSame(['GET', 'PUT', 'GET'], $methods);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame(1, $result->evidence->consequentialWriteAttempts);
    }

    #[Test]
    public function ambiguous_put_reconciles_with_get_and_never_second_put(): void
    {
        [$executor, $transport] = $this->executor(responder: function (): ConnectorHttpResult {
            static $count = 0;
            $count++;

            return match ($count) {
                1 => new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(['name' => 'Old Name']), JSON_THROW_ON_ERROR),
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
        [$_, $variant] = $this->createProductVariant($workspace);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'SKU-TEST-1',
        ]);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
            adobeBaseCurrency: 'UAH',
        ));

        $methods = array_map(
            static fn ($request) => $request->request->getMethod(),
            $transport->recordedRequests,
        );

        $this->assertSame(['GET', 'PUT', 'GET'], $methods);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertSame('trusted_link_put_ambiguous', $result->evidence->reasonCode);
    }

    #[Test]
    public function linked_identity_missing_returns_fail_closed_without_recreate_post(): void
    {
        [$executor, $transport] = $this->executor(responder: fn () => new ConnectorHttpResult(
            404,
            [],
            AdobeProductCommandTestFixtures::trustedMissing404Body('SKU-TEST-1'),
        ));

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'SKU-TEST-1',
        ]);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('linked_remote_missing', $result->evidence->reasonCode);
        $this->assertSame(1, $transport->sendCount);
    }

    #[Test]
    public function erl_is_created_only_when_ownership_policy_approves(): void
    {
        [$executor, $transport] = $this->executor(
            ownershipPolicy: new class implements AdobeProductOwnershipTrustPolicy
            {
                public function canPersistNewLink(
                    AdobeProductDesiredState $desiredState,
                    AdobeProductObservedState $observedState,
                ): bool {
                    return $desiredState->sku === $observedState->sku;
                }
            },
            responder: function (): ConnectorHttpResult {
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
                        json_encode(['product' => AdobeProductCommandTestFixtures::remoteProductPayload()], JSON_THROW_ON_ERROR),
                    ),
                    3 => new ConnectorHttpResult(
                        200,
                        [],
                        json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(), JSON_THROW_ON_ERROR),
                    ),
                    default => new ConnectorHttpResult(500, [], '{}'),
                };
            },
        );

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownApplied, $result->appliedStateKnowledge);
        $this->assertTrue($result->evidence->externalRecordLinkPersisted);
        $this->assertSame(1, ExternalRecordLink::query()->count());
    }

    #[Test]
    public function erl_persistence_collision_after_remote_application_returns_conservative_result_without_external_retry(): void
    {
        $persister = new ThrowingAdobeProductExternalRecordLinkPersister;

        [$executor, $transport] = $this->executor(
            ownershipPolicy: new class implements AdobeProductOwnershipTrustPolicy
            {
                public function canPersistNewLink(
                    AdobeProductDesiredState $desiredState,
                    AdobeProductObservedState $observedState,
                ): bool {
                    return true;
                }
            },
            persister: $persister,
            responder: function (): ConnectorHttpResult {
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
                        json_encode(['product' => AdobeProductCommandTestFixtures::remoteProductPayload()], JSON_THROW_ON_ERROR),
                    ),
                    3 => new ConnectorHttpResult(
                        200,
                        [],
                        json_encode(AdobeProductCommandTestFixtures::remoteProductPayload(), JSON_THROW_ON_ERROR),
                    ),
                    default => new ConnectorHttpResult(500, [], '{}'),
                };
            },
        );

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
            adobeBaseCurrency: 'UAH',
        ));

        $methods = array_map(
            static fn ($request) => $request->request->getMethod(),
            $transport->recordedRequests,
        );

        $this->assertSame(['GET', 'POST', 'GET'], $methods);
        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertSame('no_link_post_reconciled_link_persistence_failed', $result->evidence->reasonCode);
    }

    #[Test]
    public function result_evidence_does_not_persist_raw_secrets_or_http_bodies(): void
    {
        [$executor] = $this->executor(responder: fn () => new ConnectorHttpResult(
            404,
            [],
            AdobeProductCommandTestFixtures::trustedMissing404Body('SKU-TEST-1'),
        ));

        $result = $executor->execute($this->defaultInput());
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Authorization', $encoded);
        $this->assertStringNotContainsString('oauth', strtolower($encoded));
        $this->assertStringNotContainsString('cs_live', $encoded);
        $this->assertStringNotContainsString('at_live', $encoded);
    }

    /**
     * @return array{0: AdobeProductSimpleCommandExecutor, 1: RecordingConnectorHttpTransport}
     */
    private function executor(
        ?AdobeProductOwnershipTrustPolicy $ownershipPolicy = null,
        ?AdobeProductExternalRecordLinkPersistence $persister = null,
        ?\Closure $responder = null,
    ): array {
        $transport = new RecordingConnectorHttpTransport(
            $responder ?? fn () => new ConnectorHttpResult(500, [], '{"message":"unexpected"}'),
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
            app(AdobePaaSRequestContextFactory::class),
            $client,
            new AdobeProductRemoteStateComparator,
            $linkGuard,
            $persister ?? new AdobeProductExternalRecordLinkPersister($linkGuard),
            $ownershipPolicy ?? new ConservativeAdobeProductOwnershipTrustPolicy,
        );

        return [$executor, $transport];
    }

    private function defaultInput(): AdobeProductSimpleCommandInput
    {
        $workspace = $this->defaultWorkspace();

        return new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $this->createConnectorAccount($workspace)->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult(),
            adobeBaseCurrency: 'UAH',
        );
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
}
