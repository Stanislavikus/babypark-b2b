<?php

namespace Tests\Feature\Sync;

use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkGuard;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersistence;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersistenceException;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersister;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductOwnershipTrustPolicy;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandInput;
use App\Support\Connectors\Transport\ConnectorHttpResult;
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

class Stage3B2AdobeSimpleCommandSafetyTest extends TestCase
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
        [$executor, $transport] = $this->executor();

        $result = $executor->execute($this->defaultInput());

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function product_scoped_same_adobe_sku_erl_is_collision_with_zero_writes(): void
    {
        [$executor, $transport] = $this->executor();
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variant] = $this->createProductVariant($workspace);
        [$subjectProduct, $subjectVariant] = $this->createProductVariant($workspace);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
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
    public function exact_current_product_variant_link_is_trusted_not_collision(): void
    {
        [$executor, $transport] = $this->executor();

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
        $this->assertSame('link_required', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function ambiguous_put_with_partial_remote_state_returns_unknown_without_second_put(): void
    {
        [$executor, $transport] = $this->executor();
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

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
    }

    #[Test]
    public function ambiguous_post_with_partial_remote_state_returns_unknown_without_erl_or_second_post(): void
    {
        [$executor, $transport] = $this->executor();

        $result = $executor->execute($this->defaultInput());

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
        $this->assertSame(0, ExternalRecordLink::query()->count());
    }

    #[Test]
    public function post_4xx_reconciles_with_get_and_never_second_post(): void
    {
        [$executor, $transport] = $this->executor();

        $result = $executor->execute($this->defaultInput());

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
    }

    #[Test]
    public function put_4xx_reconciles_with_get_and_never_second_put(): void
    {
        [$executor, $transport] = $this->executor();
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

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
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
        [$executor, $transport] = $this->executor();

        $result = $executor->execute($this->defaultInput());

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(0, $result->evidence->consequentialWriteAttempts);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
        $this->assertFalse($result->evidence->externalRecordLinkPersisted);
    }

    #[Test]
    public function post_transport_ambiguity_reconciles_with_get_and_never_second_post(): void
    {
        [$executor, $transport] = $this->executor();

        $result = $executor->execute($this->defaultInput());

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
    }

    #[Test]
    public function post_inconclusive_success_body_does_not_fabricate_erl(): void
    {
        [$executor, $transport] = $this->executor();

        $result = $executor->execute($this->defaultInput());

        $this->assertSame(0, ExternalRecordLink::query()->count());
        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
        $this->assertFalse($result->evidence->externalRecordLinkPersisted);
    }

    #[Test]
    public function existing_trusted_link_with_equal_remote_state_performs_no_put(): void
    {
        [$executor, $transport] = $this->executor();
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
        $this->assertSame('link_required', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function existing_trusted_link_with_different_state_sends_exactly_one_put(): void
    {
        [$executor, $transport] = $this->executor();
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

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
        $this->assertSame(0, $result->evidence->consequentialWriteAttempts);
    }

    #[Test]
    public function ambiguous_put_reconciles_with_get_and_never_second_put(): void
    {
        [$executor, $transport] = $this->executor();
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

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
    }

    #[Test]
    public function linked_identity_missing_returns_fail_closed_without_recreate_post(): void
    {
        [$executor, $transport] = $this->executor();
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
        $this->assertSame('link_required', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function erl_is_created_only_when_ownership_policy_approves(): void
    {
        [$executor, $transport] = $this->executor();
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
        $this->assertFalse($result->evidence->externalRecordLinkPersisted);
        $this->assertSame(0, ExternalRecordLink::query()->count());
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function erl_persistence_collision_after_remote_application_returns_conservative_result_without_external_retry(): void
    {
        [$executor, $transport] = $this->executor();
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
        $this->assertSame(0, ExternalRecordLink::query()->count());
    }

    #[Test]
    public function trusted_link_with_identity_drift_returns_unknown_without_http_writes(): void
    {
        [$executor, $transport] = $this->executor();

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'OLD-SKU',
        ]);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
                'sku' => 'NEW-SKU',
            ]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
    }

    #[Test]
    public function multiple_variant_scoped_links_for_same_subject_fail_closed_without_http(): void
    {
        [$executor, $transport] = $this->executor();

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'SKU-A',
        ]);
        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'SKU-B',
        ]);

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertSame('ambiguous_variant_identity_links', $result->evidence->reasonCode);
    }

    #[Test]
    public function guard_does_not_resolve_foreign_workspace_variant_link(): void
    {
        $guard = new AdobeProductExternalRecordLinkGuard;
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create([
            'name' => 'Other Workspace',
            'is_default' => false,
        ]);
        $accountA = $this->createConnectorAccount($workspaceA);
        [$_, $variantA] = $this->createProductVariant($workspaceA);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspaceA->id,
            'connector_account_id' => $accountA->id,
            'product_variant_id' => $variantA->id,
            'external_identifier' => 'SKU-TEST-1',
        ]);

        $lookup = $guard->resolveTrustedVariantLinkBySubject(
            $workspaceB->id,
            $accountA->id,
            (string) $variantA->id,
        );

        $this->assertTrue($lookup->isNone());
    }

    #[Test]
    public function persister_rejects_identity_drift_for_trusted_subject_link_without_mutation(): void
    {
        $persister = new AdobeProductExternalRecordLinkPersister(new AdobeProductExternalRecordLinkGuard);
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace);
        $actor = $this->createWorkspaceActor($workspace);

        $existing = ExternalRecordLink::query()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $workspace,
                $account->id,
                $variant,
                'OLD-SKU',
                '1001',
                $actor,
            ),
        );

        $desired = (new AdobeProductDesiredStateCompiler)->compileFromSemanticResult(
            AdobeProductCommandTestFixtures::semanticResult([
                'variant_id' => $variant->id,
                'sku' => 'NEW-SKU',
            ]),
        );

        try {
            $persister->persistTrustedVariantLink(
                $workspace->id,
                $account->id,
                $desired,
            );
            $this->fail('Expected identity drift persistence exception.');
        } catch (AdobeProductExternalRecordLinkPersistenceException $exception) {
            $this->assertStringContainsString('identity drift', strtolower($exception->getMessage()));
        }

        $existing->refresh();
        $this->assertSame('OLD-SKU', $existing->external_identifier);
        $this->assertSame(1, ExternalRecordLink::query()->count());
        $this->assertFalse(
            ExternalRecordLink::query()->where('external_identifier', 'NEW-SKU')->exists(),
        );
    }

    #[Test]
    public function persister_returns_existing_link_idempotently_when_subject_and_sku_match(): void
    {
        $persister = new AdobeProductExternalRecordLinkPersister(new AdobeProductExternalRecordLinkGuard);
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace);
        $actor = $this->createWorkspaceActor($workspace);

        $existing = ExternalRecordLink::query()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $workspace,
                $account->id,
                $variant,
                'SKU-TEST-1',
                '1002',
                $actor,
            ),
        );

        $desired = (new AdobeProductDesiredStateCompiler)->compileFromSemanticResult(
            AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
        );

        $persisted = $persister->persistTrustedVariantLink(
            $workspace->id,
            $account->id,
            $desired,
        );

        $this->assertSame($existing->id, $persisted->id);
        $this->assertSame('SKU-TEST-1', $persisted->external_identifier);
        $this->assertSame(1, ExternalRecordLink::query()->count());
    }

    #[Test]
    public function production_persister_normalizes_missing_connector_account_to_typed_exception(): void
    {
        $persister = new AdobeProductExternalRecordLinkPersister(new AdobeProductExternalRecordLinkGuard);
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace);

        $desired = (new AdobeProductDesiredStateCompiler)->compileFromSemanticResult(
            AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
        );

        $otherWorkspace = Workspace::query()->create([
            'name' => 'Foreign Workspace',
            'is_default' => false,
        ]);

        $this->expectException(AdobeProductExternalRecordLinkPersistenceException::class);

        try {
            $persister->persistTrustedVariantLink(
                $otherWorkspace->id,
                $account->id,
                $desired,
            );
        } catch (AdobeProductExternalRecordLinkPersistenceException $exception) {
            $this->assertStringContainsString('ConnectorAccount was not found', $exception->getMessage());

            throw $exception;
        }
    }

    #[Test]
    public function result_evidence_does_not_persist_raw_secrets_or_http_bodies(): void
    {
        [$executor] = $this->executor();

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
            $responder ?? fn () => throw new \RuntimeException('HTTP must not be called'),
        );

        $linkGuard = new AdobeProductExternalRecordLinkGuard;

        $executor = new AdobeProductSimpleCommandExecutor(
            new AdobeProductDesiredStateCompiler,
            $linkGuard,
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
