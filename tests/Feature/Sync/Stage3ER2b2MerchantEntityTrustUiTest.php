<?php

namespace Tests\Feature\Sync;

use App\Enums\EntityTrust\EntityTrustConfirmationMode;
use App\Enums\EntityTrust\EntityTrustFailureReason;
use App\Enums\EntityTrust\EntityTrustReadinessStatus;
use App\Filament\Pages\Sync\ManageAdobeProductsExportPreview;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\EntityTrust\EntityTrustFailureReasonPresenter;
use App\Services\Sync\EntityTrust\EntityTrustReviewFlowStore;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Concerns\InteractsWithEntityTrustFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\Support\Sync\EntityTrust\EntityTrustAdobeTransportResponder;
use Tests\TestCase;

class Stage3ER2b2MerchantEntityTrustUiTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use CreatesMerchantConfirmedExternalRecordLinks;
    use InteractsWithEntityTrustFixtures;
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private EntityTrustAdobeTransportResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();

        $this->responder = new EntityTrustAdobeTransportResponder;
        $this->bindEntityTrustTransport($this->responder);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function live_only_actor_sees_entity_trust_section_in_live_area_but_cannot_review(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('UI-LIVEONLY-SKU', 5001);

        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::RUN_SYNC_LIVE,
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('entityTrustSectionVisible', true)
            ->assertSet('entityTrustCanReviewOrConfirm', false)
            ->assertSet('entityTrustWorkingSet', [])
            ->assertSee('data-testid="sync-live-entity-trust-section"', false)
            ->assertSee(__('entity_trust.section.no_permission'));
    }

    #[Test]
    public function dual_permission_actor_sees_working_set_with_initial_link_required_row(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('UI-DUAL-SKU', 5010);
        $actor = $this->createEntityTrustActor($account->workspace);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('entityTrustSectionVisible', true)
            ->assertSet('entityTrustCanReviewOrConfirm', true)
            ->assertCount('entityTrustWorkingSet', 1)
            ->assertSee('data-testid="sync-live-entity-trust-row"', false)
            ->assertSee('data-testid="sync-live-entity-trust-action-review"', false);
    }

    #[Test]
    public function request_review_returns_safe_outcome_with_opaque_flow_id_and_no_token_leak(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('UI-REVIEW-SKU', 5100);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id);

        $component
            ->assertSet('entityTrustOutcomeCategory', 'actionable')
            ->assertSet('entityTrustOutcomeReadyForConfirmation', true)
            ->assertSet('entityTrustActiveReviewProductId', (string) $product->id);

        $flowId = $component->get('entityTrustReviewFlowId');
        $this->assertIsString($flowId);
        $this->assertStringStartsWith('etflow_', $flowId);
        $this->assertStringNotContainsString('review', strtolower($flowId));
        $this->assertStringNotContainsString('token', strtolower($flowId));

        // Token MUST never appear in any dehydrated state.
        $dehydrated = $component->snapshot;
        $serialized = json_encode($dehydrated, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('reviewToken', $serialized);
        $this->assertStringNotContainsString('entityTrustExplicitRelink', $serialized);
        $this->assertStringNotContainsString('explicit_relink', $serialized);
    }

    #[Test]
    public function confirm_with_valid_flow_persists_link_and_never_writes_to_remote(): void
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture('UI-CONFIRM-SKU', 5200);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id)
            ->call('confirmEntityTrust');

        $component
            ->assertSet('entityTrustOutcomeCategory', 'success')
            ->assertSet('entityTrustOutcomeIsConfirmation', true)
            ->assertSet('entityTrustActiveReviewFlowId', null)
            ->assertSet('entityTrustActiveReviewProductId', null)
            ->assertSet('entityTrustReviewFlowId', null);

        $this->assertSame(1, ExternalRecordLink::withoutWorkspaceScope()->where('product_variant_id', $variant->id)->count());
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function confirm_with_consumed_flow_fails_closed_with_safe_copy(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('UI-STALE-SKU', 5210);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id);

        $flowId = $component->get('entityTrustReviewFlowId');
        $this->assertNotNull($flowId);

        // First confirm succeeds.
        $component->call('confirmEntityTrust');

        // After a successful confirm, the flow id is cleared. A second confirm
        // is a safe no-op (flow is gone from the store). This is the
        // fail-closed path: the merchant can't replay a consumed flow.
        $component->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', null);

        $this->assertStringNotContainsString('flow not found', strtolower($component->html()));
    }

    #[Test]
    public function cancel_drops_flow_id_and_clears_state_without_writes(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('UI-CANCEL-SKU', 5220);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id)
            ->call('cancelEntityTrustFlow');

        $component
            ->assertSet('entityTrustReviewFlowId', null)
            ->assertSet('entityTrustProductId', null)
            ->assertSet('entityTrustActiveReviewFlowId', null)
            ->assertSet('entityTrustOutcomeCategory', null);

        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function request_review_without_dual_permission_is_rejected(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('UI-PERM-SKU', 5230);
        $actor = User::factory()->create(['is_active' => true]);
        // Grant page access (RUN_SYNC_LIVE) but NOT the entity-trust dual permission
        // (which requires BOTH manage_sync_configurations AND run_sync_live).
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::RUN_SYNC_LIVE,
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id)
            ->assertSet('entityTrustOutcomeCategory', 'security');
    }

    #[Test]
    public function confirm_without_active_flow_is_safe_noop(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('UI-NOOP-SKU', 5240);
        $actor = $this->createEntityTrustActor($account->workspace);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', null);

        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function relink_flow_uses_explicit_relink_path_and_passes_parent_sku_hint(): void
    {
        [$account, $product,, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustRelink', (string) $product->id, $parentSku)
            ->assertSet('entityTrustRelinkParentSku', $parentSku);
    }

    #[Test]
    public function configurable_family_working_set_row_marks_is_configurable_family(): void
    {
        [$account, $product] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('entityTrustWorkingSet.0.is_configurable_family', true)
            ->assertSet('entityTrustWorkingSet.0.readiness_value', EntityTrustReadinessStatus::InitialLinkRequired->value);
    }

    #[Test]
    public function no_consequential_magento_writes_occur_under_any_merchant_path(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('UI-NOWRITE-SKU', 5250);
        $actor = $this->createEntityTrustActor($account->workspace);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id)
            ->call('confirmEntityTrust')
            ->call('requestEntityTrustReview', (string) $product->id)
            ->call('cancelEntityTrustFlow');

        $this->assertFalse(
            $this->responder->hasConsequentialWrite(),
            'Merchant UI must never trigger POST/PUT/PATCH/DELETE against Magento.',
        );
    }

    #[Test]
    public function every_entity_trust_failure_reason_has_a_safe_merchant_mapping(): void
    {
        $presenter = app(EntityTrustFailureReasonPresenter::class);

        foreach (EntityTrustFailureReason::cases() as $case) {
            $mapping = $presenter->present($case);

            $this->assertNotEmpty($mapping['label_key'], "Missing label_key for {$case->value}");
            $this->assertNotEmpty($mapping['explanation_key'], "Missing explanation_key for {$case->value}");
            $this->assertNotEmpty($mapping['available_action'], "Missing available_action for {$case->value}");
            $this->assertNotSame(
                'entity_trust.failure.fallback.label',
                $mapping['label_key'],
                "{$case->value} hit a default fallback (mapping must be exhaustive).",
            );
        }
    }

    #[Test]
    public function review_token_never_appears_in_rendered_html(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('UI-HTML-SKU', 5260);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id);

        $dom = (string) $component->html();
        $this->assertStringNotContainsString('reviewToken', $dom);
        $this->assertStringNotContainsString('explicitRelink', $dom);
        $this->assertStringNotContainsString('explicit_relink', $dom);
    }

    #[Test]
    public function flow_store_consume_is_single_use_and_validates_binding(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('UI-FLOWSTORE-SKU', 5270);
        $actor = $this->createEntityTrustActor($account->workspace);

        $workspace = $account->workspace;
        $store = app(EntityTrustReviewFlowStore::class);

        $flowId = $store->issue(
            $actor,
            $workspace,
            $account->id,
            (string) $product->id,
            'test-token',
            EntityTrustConfirmationMode::SimpleVariant,
            null,
            false,
        );

        $first = $store->consume($actor, $workspace, $account->id, (string) $product->id, $flowId);
        $this->assertNotNull($first);
        $this->assertSame('test-token', $first->reviewToken);

        $second = $store->consume($actor, $workspace, $account->id, (string) $product->id, $flowId);
        $this->assertNull($second);

        $mismatched = $store->consume($actor, $workspace, $account->id, 'wrong-product', $flowId);
        $this->assertNull($mismatched);
    }

    #[Test]
    public function revocation_of_dual_permission_clears_entity_trust_action(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('UI-REVOKE-SKU', 5280);
        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::RUN_SYNC_LIVE,
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('entityTrustCanReviewOrConfirm', true);

        $this->revokeAllWorkspaceRoles($membership);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::RUN_SYNC_LIVE,
        ]);

        $component->call('refreshPresentation')
            ->assertSet('entityTrustCanReviewOrConfirm', false)
            ->assertSee('data-testid="sync-live-entity-trust-no-permission"', false);
    }

    #[Test]
    public function last_outcome_persists_until_next_action(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('UI-OUTCOME-SKU', 5290);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id)
            ->assertSet('entityTrustOutcomeCategory', 'actionable')
            ->call('cancelEntityTrustFlow')
            ->assertSet('entityTrustOutcomeCategory', null)
            ->call('requestEntityTrustReview', (string) $product->id)
            ->assertSet('entityTrustOutcomeCategory', 'actionable');
    }

    /**
     * @return array{0: Product, 1: Workspace}
     */
    private function resolveAccountForConfigurableFixture(): array
    {
        // For configurable fixtures the projector reports is_configurable_family on the
        // product; we just need the workspace under which it was created. The account
        // is reused from the prior seed via the cached default fixture.
        $workspace = $this->defaultWorkspace();
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('auth_profile', 'adobe_commerce_paas_oauth1_integration')
            ->first();

        return [$account, $workspace];
    }

    /**
     * @return array{0: ConnectorAccount, 1: Product, 2: list<ProductVariant>, 3: string}
     */
    private function seedConfigurableReadyFixture(): array
    {
        $account = $this->createConnectorAccount();
        $this->prepareConfigurableEntityTrustConfiguration($account);
        [$product, $variants] = $this->createConfigurableEntityTrustProduct($account->workspace, 'UI-CFG-SKU', 'MERCHANT-PARENT-SKU');

        $this->responder->registerProduct('MERCHANT-PARENT-SKU', 7777, 'configurable');
        foreach ($variants as $i => $variant) {
            $this->responder->registerProduct($variant->sku, 8000 + $i, 'simple');
        }
        $this->responder->registerConfigurableChildren('MERCHANT-PARENT-SKU', [
            ['sku' => $variants[0]->sku, 'id' => 8000, 'type_id' => 'simple'],
            ['sku' => $variants[1]->sku, 'id' => 8001, 'type_id' => 'simple'],
            ['sku' => 'EXTRA-CHILD-SKU', 'id' => 8002, 'type_id' => 'simple'],
        ]);

        return [$account, $product, $variants, 'MERCHANT-PARENT-SKU'];
    }

    /**
     * @return array{0: ConnectorAccount, 1: Product, 2: ProductVariant}
     */
    private function seedSimpleReadyFixture(string $sku, int $logicalEntityId): array
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product, $variant] = $this->createSimpleEntityTrustProduct($account->workspace, $sku);

        $this->responder->registerProduct($sku, $logicalEntityId, 'simple', [
            'price' => 199.0,
            'name' => 'Entity Trust '.$sku,
        ]);

        return [$account, $product, $variant];
    }
}
