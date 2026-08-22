<?php

namespace Tests\Feature\Sync;

use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableCommandInput;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableParentCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableParentSkuGenerator;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredStateCompiler;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkGuard;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersistenceException;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersister;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandExecutor;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductSimpleCommandInput;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeConfigurableCommandTestFixtures;
use Tests\Support\Connectors\AdobePaaS\Command\AdobeProductCommandTestFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class Stage3ER2aTrustedErlSemanticsTest extends TestCase
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
    public function migration_adds_provenance_columns_without_backfill(): void
    {
        $this->assertTrue(Schema::hasColumn('external_record_links', 'trust_origin'));
        $this->assertTrue(Schema::hasColumn('external_record_links', 'external_record_discriminator'));
        $this->assertTrue(Schema::hasColumn('external_record_links', 'established_by_workspace_user_id'));
        $this->assertTrue(Schema::hasColumn('external_record_links', 'established_at'));

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variant] = $this->createProductVariant($workspace);

        $legacy = ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'LEGACY-SKU',
        ]);

        $legacy->refresh();

        $this->assertNull($legacy->trust_origin);
        $this->assertNull($legacy->external_record_discriminator);
        $this->assertNull($legacy->established_by_workspace_user_id);
        $this->assertNull($legacy->established_at);
        $this->assertFalse($legacy->hasMerchantConfirmedTrust());
    }

    #[Test]
    public function actor_foreign_key_rejects_cross_workspace_workspace_user(): void
    {
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);
        $account = $this->createConnectorAccount($workspaceA);
        [$_, $variant] = $this->createProductVariant($workspaceA);
        $foreignActor = $this->createWorkspaceActor($workspaceB);

        $this->expectException(QueryException::class);

        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceA->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'CROSS-ACTOR',
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => '42',
            'established_by_workspace_user_id' => $foreignActor->id,
            'established_at' => now(),
        ]);
    }

    #[Test]
    public function actor_foreign_key_accepts_same_workspace_workspace_user(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$_, $variant] = $this->createProductVariant($workspace);
        $actor = $this->createWorkspaceActor($workspace);

        $link = ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $workspace,
                $account->id,
                $variant,
                'TRUSTED-SKU',
                '9001',
                $actor,
            ),
        );

        $this->assertTrue($link->hasMerchantConfirmedTrust());
    }

    #[Test]
    public function legacy_erl_is_untrusted(): void
    {
        $link = new ExternalRecordLink([
            'trust_origin' => null,
            'external_record_discriminator' => null,
            'established_by_workspace_user_id' => null,
            'established_at' => null,
        ]);

        $this->assertFalse($link->hasMerchantConfirmedTrust());
    }

    #[Test]
    public function origin_only_is_untrusted(): void
    {
        $link = new ExternalRecordLink([
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
        ]);

        $this->assertFalse($link->hasMerchantConfirmedTrust());
    }

    #[Test]
    public function origin_and_discriminator_only_is_untrusted(): void
    {
        $link = new ExternalRecordLink([
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => '42',
        ]);

        $this->assertFalse($link->hasMerchantConfirmedTrust());
    }

    #[Test]
    public function merchant_confirmed_without_timestamp_is_untrusted(): void
    {
        $link = new ExternalRecordLink([
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => '42',
            'established_by_workspace_user_id' => (string) Str::uuid(),
            'established_at' => null,
        ]);

        $this->assertFalse($link->hasMerchantConfirmedTrust());
    }

    #[Test]
    public function complete_merchant_confirmed_tuple_is_trusted(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->createWorkspaceActor($workspace);

        $link = new ExternalRecordLink([
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => '42',
            'established_by_workspace_user_id' => $actor->id,
            'established_at' => now(),
        ]);

        $this->assertTrue($link->hasMerchantConfirmedTrust());
    }

    #[Test]
    public function unknown_origin_is_untrusted(): void
    {
        $link = new ExternalRecordLink([
            'trust_origin' => 'imported',
            'external_record_discriminator' => '42',
            'established_by_workspace_user_id' => (string) Str::uuid(),
            'established_at' => now(),
        ]);

        $this->assertFalse($link->hasMerchantConfirmedTrust());
    }

    #[Test]
    public function foreign_workspace_erl_cannot_satisfy_trusted_lookup(): void
    {
        $guard = new AdobeProductExternalRecordLinkGuard;
        $workspaceA = $this->defaultWorkspace();
        $workspaceB = Workspace::query()->create(['name' => 'Foreign', 'is_default' => false]);
        $account = $this->createConnectorAccount($workspaceA);
        [$_, $variant] = $this->createProductVariant($workspaceA);
        $actor = $this->createWorkspaceActor($workspaceA);

        ExternalRecordLink::query()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $workspaceA,
                $account->id,
                $variant,
                'SKU-TEST-1',
                '100',
                $actor,
            ),
        );

        $lookup = $guard->resolveTrustedVariantLinkBySubject(
            $workspaceB->id,
            $account->id,
            (string) $variant->id,
        );

        $this->assertTrue($lookup->isNone());
    }

    #[Test]
    public function same_sku_on_different_internal_subject_collides(): void
    {
        $guard = new AdobeProductExternalRecordLinkGuard;
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variantA] = $this->createProductVariant($workspace);
        [, $variantB] = $this->createProductVariant($workspace);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variantA->id,
            'external_identifier' => 'SHARED-SKU',
        ]);

        $this->assertTrue($guard->hasCrossSubjectCollision(
            $workspace->id,
            $account->id,
            'SHARED-SKU',
            (string) $variantB->id,
        ));
    }

    #[Test]
    public function same_discriminator_on_different_internal_subject_collides_even_when_sku_differs(): void
    {
        $guard = new AdobeProductExternalRecordLinkGuard;
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [, $variantA] = $this->createProductVariant($workspace);
        [, $variantB] = $this->createProductVariant($workspace);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variantA->id,
            'external_identifier' => 'SKU-A',
            'external_record_discriminator' => '777',
        ]);

        $this->assertTrue($guard->hasVariantDiscriminatorCrossSubjectCollision(
            $workspace->id,
            $account->id,
            '777',
            (string) $variantB->id,
        ));
    }

    #[Test]
    public function different_discriminator_and_different_sku_do_not_false_collide(): void
    {
        $guard = new AdobeProductExternalRecordLinkGuard;
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [, $variantA] = $this->createProductVariant($workspace);
        [, $variantB] = $this->createProductVariant($workspace);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variantA->id,
            'external_identifier' => 'SKU-A',
            'external_record_discriminator' => '111',
        ]);

        $this->assertFalse($guard->hasCrossSubjectCollision(
            $workspace->id,
            $account->id,
            'SKU-B',
            (string) $variantB->id,
        ));
        $this->assertFalse($guard->hasVariantDiscriminatorCrossSubjectCollision(
            $workspace->id,
            $account->id,
            '222',
            (string) $variantB->id,
        ));
    }

    #[Test]
    public function legacy_ambiguity_remains_fail_closed(): void
    {
        [$executor, $transport] = $this->executor();
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [, $variant] = $this->createProductVariant($workspace);

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

        $this->assertSame(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $result->appliedStateKnowledge);
        $this->assertSame('ambiguous_variant_identity_links', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(0, $result->evidence->consequentialWriteAttempts);
    }

    #[Test]
    public function no_trusted_erl_returns_link_required_with_zero_writes(): void
    {
        [$executor, $transport] = $this->executor();
        $result = $executor->execute($this->defaultInput());

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('link_required', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(0, $result->evidence->consequentialWriteAttempts);
    }

    #[Test]
    public function complete_trusted_erl_returns_entity_bound_bridge_required_with_zero_writes(): void
    {
        [$executor, $transport] = $this->executor();
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [, $variant] = $this->createProductVariant($workspace);
        $actor = $this->createWorkspaceActor($workspace);

        ExternalRecordLink::query()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $workspace,
                $account->id,
                $variant,
                'SKU-TEST-1',
                '501',
                $actor,
            ),
        );

        $result = $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('entity_bound_mutation_bridge_required', $result->evidence->reasonCode);
        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(0, $result->evidence->consequentialWriteAttempts);
    }

    #[Test]
    public function configurable_parent_trusted_erl_fails_closed_before_http(): void
    {
        $transport = new RecordingConnectorHttpTransport(fn () => throw new \RuntimeException('HTTP must not be called'));
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [$product, $variant] = $this->createProductVariant($workspace);
        $actor = $this->createWorkspaceActor($workspace);

        ExternalRecordLink::query()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $workspace,
                $account->id,
                $product,
                'CFG-PARENT',
                '900',
                $actor,
            ),
        );

        $executor = new AdobeConfigurableParentCommandExecutor(new AdobeProductExternalRecordLinkGuard);
        $semantic = AdobeConfigurableCommandTestFixtures::configurableSemanticResult($product->id);
        $desired = (new AdobeConfigurableDesiredStateCompiler(new AdobeConfigurableParentSkuGenerator))
            ->compile($semantic, $workspace->id, null);
        $input = new AdobeConfigurableCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: $semantic,
            desiredState: $desired,
            adobeBaseCurrency: 'UAH',
            metadata: null,
        );

        $result = $executor->execute($input);

        $this->assertSame(AdobeProductAppliedStateKnowledge::KnownNotApplied, $result->appliedStateKnowledge);
        $this->assertSame('entity_bound_mutation_bridge_required', $result->reasonCode);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function execution_cannot_create_or_upgrade_erl(): void
    {
        [$executor, $transport] = $this->executor();
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [, $variant] = $this->createProductVariant($workspace);

        ExternalRecordLink::query()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'LEGACY-SKU',
        ]);

        $before = ExternalRecordLink::query()->count();

        $executor->execute(new AdobeProductSimpleCommandInput(
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            semanticResult: AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
            adobeBaseCurrency: 'UAH',
        ));

        $this->assertSame($before, ExternalRecordLink::query()->count());
        $this->assertNull(ExternalRecordLink::query()->first()?->trust_origin);
        $this->assertSame(0, $transport->sendCount);
    }

    #[Test]
    public function persister_cannot_mint_merchant_confirmed_provenance_from_execution(): void
    {
        $persister = new AdobeProductExternalRecordLinkPersister(new AdobeProductExternalRecordLinkGuard);
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        [, $variant] = $this->createProductVariant($workspace);

        $desired = (new AdobeProductDesiredStateCompiler)->compileFromSemanticResult(
            AdobeProductCommandTestFixtures::semanticResult(['variant_id' => $variant->id]),
        );

        $this->expectException(AdobeProductExternalRecordLinkPersistenceException::class);
        $this->expectExceptionMessage('Merchant-confirmed');

        $persister->persistTrustedVariantLink($workspace->id, $account->id, $desired);
    }

    /**
     * @return array{0: AdobeProductSimpleCommandExecutor, 1: RecordingConnectorHttpTransport}
     */
    private function executor(): array
    {
        $transport = new RecordingConnectorHttpTransport(
            fn () => throw new \RuntimeException('HTTP must not be called'),
        );

        $executor = new AdobeProductSimpleCommandExecutor(
            new AdobeProductDesiredStateCompiler,
            new AdobeProductExternalRecordLinkGuard,
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
