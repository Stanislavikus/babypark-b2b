<?php

namespace Tests\Feature\Sync;

use App\Enums\EntityTrust\EntityTrustConfirmationMode;
use App\Enums\EntityTrust\EntityTrustFailureReason;
use App\Enums\UserRole;
use App\Filament\Pages\Sync\ManageAdobeProductsExportPreview;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorAccountSettingsService;
use App\Services\Connectors\UpdateConnectorAccountInput;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustConfirmationService;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustReviewService;
use App\Services\Sync\EntityTrust\EntityTrustReviewFlowStore;
use App\Services\Sync\SyncConfigurationLookupService;
use App\Support\Connectors\CredentialMutation;
use App\Support\Sync\EntityTrust\EntityTrustReviewFlowPayload;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Concerns\InteractsWithEntityTrustFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\Support\Sync\EntityTrust\EntityTrustAdobeTransportResponder;
use Tests\TestCase;

/**
 * R2b-2 CORRECTION PASS regression tests.
 *
 * Each test maps to a specific defect listed in the Stage 3E-R2b-2 correction
 * pass. Tests reuse the same R2b-1 services and fixtures the existing R2b-1
 * suite already uses; nothing here re-implements backend logic.
 */
class Stage3ER2b2CorrectionPassTest extends TestCase
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

    // -----------------------------------------------------------------
    // Authorization path coverage (Fix #8)
    // -----------------------------------------------------------------

    #[Test]
    public function missing_manage_sync_configurations_permission_blocks_review_with_security_outcome(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-MANAGEONLY-SKU', 7001);
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::RUN_SYNC_LIVE,
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id)
            ->assertSet('entityTrustOutcomeCategory', 'security')
            ->assertSet('entityTrustOutcomeReadyForConfirmation', false);

        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function missing_run_sync_live_permission_keeps_entity_trust_non_actionable_even_if_a_livewire_action_is_crafted(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-LIVEONLY-MISSING-SKU', 7002);
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertOk()
            ->assertSet('entityTrustSectionVisible', false)
            ->assertSet('entityTrustCanReviewOrConfirm', false)
            ->call('requestEntityTrustReview', (string) $product->id)
            ->assertSet('entityTrustOutcomeCategory', 'security')
            ->assertSet('entityTrustReviewFlowId', null)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', false);

        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function revocation_of_dual_permission_between_review_and_confirm_fails_closed(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-REVOKE-SKU', 7003);
        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::RUN_SYNC_LIVE,
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', true);

        // Permission revoked between Review and Confirm.
        $this->revokeAllWorkspaceRoles($membership);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::RUN_SYNC_LIVE,
        ]);

        $component->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'security')
            ->assertSet('entityTrustActiveReviewFlowId', null);

        $this->assertSame(0, ExternalRecordLink::withoutWorkspaceScope()->where('product_variant_id', $product->variants[0]->id)->count());
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function manage_sync_configurations_only_actor_can_reach_the_management_page_but_cannot_mutate_entity_trust(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-SETUP-PAGE-SKU', 7004);
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertOk()
            ->assertSet('entityTrustSectionVisible', false)
            ->assertSet('entityTrustCanReviewOrConfirm', false)
            ->call('requestEntityTrustReview', (string) $product->id)
            ->assertSet('entityTrustOutcomeCategory', 'security')
            ->assertSet('entityTrustReviewFlowId', null)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', false);
    }

    #[Test]
    public function setup_only_actor_is_forbidden_from_foreign_workspace_target(): void
    {
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign Workspace']);
        $foreignAccount = $this->createConnectorAccount($foreignWorkspace);
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions($this->defaultWorkspace(), $actor, [
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);

        $this->actingAs($actor)
            ->get(ManageAdobeProductsExportPreview::getUrl(['account' => $foreignAccount->id]))
            ->assertForbidden();
    }

    #[Test]
    public function setup_only_actor_is_forbidden_from_non_adobe_or_non_eligible_target(): void
    {
        $nonAdobeAccount = $this->createSyncSupportAccount();
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions($nonAdobeAccount->workspace, $actor, [
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);

        $this->actingAs($actor)
            ->get(ManageAdobeProductsExportPreview::getUrl(['account' => $nonAdobeAccount->id]))
            ->assertForbidden();
    }

    #[Test]
    public function setup_only_actor_can_access_management_page_for_valid_adobe_setup_target(): void
    {
        [$account] = $this->seedSimpleReadyFixture('CORR-SETUP-OK-SKU', 7005);
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);

        $this->actingAs($actor)
            ->get(ManageAdobeProductsExportPreview::getUrl(['account' => $account->id]))
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // Configurable initial review -> confirm family (Fix #1, #8)
    // -----------------------------------------------------------------

    #[Test]
    public function configurable_initial_review_with_parent_sku_hint_progresses_to_confirm_family(): void
    {
        [$account, $product, $variants, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set("entityTrustInitialLinkParentSkuByProduct.{$product->id}", $parentSku)
            ->call('requestEntityTrustReview', (string) $product->id);

        $component
            ->assertSet('entityTrustReviewIsConfigurable', true)
            ->assertSet('entityTrustOutcomeCategory', 'actionable')
            ->assertSet('entityTrustOutcomeReadyForConfirmation', true)
            ->assertSet('entityTrustActiveMode', __('entity_trust.mode.configurable_existing_parent'));

        $flowId = $component->get('entityTrustReviewFlowId');
        $this->assertIsString($flowId);
        $this->assertStringStartsWith('etflow_', $flowId);

        $component->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'success')
            ->assertSet('entityTrustOutcomeIsConfirmation', true)
            ->assertSet('entityTrustActiveReviewFlowId', null);

        // Both intended active children must be linked after a successful
        // configurable family confirmation.
        foreach ($variants as $variant) {
            $this->assertSame(
                1,
                ExternalRecordLink::withoutWorkspaceScope()
                    ->where('product_variant_id', $variant->id)
                    ->count(),
                "Variant {$variant->sku} should be linked after configurable confirm.",
            );
        }

        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    // -----------------------------------------------------------------
    // Simple vs configurable relink (Fix #2, #8)
    // -----------------------------------------------------------------

    #[Test]
    public function simple_explicit_relink_does_not_ask_for_parent_sku_and_uses_no_hint(): void
    {
        [$account, $product] = $this->seedSimpleRelinkRequiredFixture('CORR-RELINK-SIMPLE-SKU', 7010);
        $actor = $this->createEntityTrustActor($account->workspace);

        // The Livewire method only takes a product id. The merchant must
        // never be asked for a Magento parent SKU for a simple product.
        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('entityTrustWorkingSet.0.available_action', 'relink')
            ->call('requestEntityTrustRelink', (string) $product->id);

        $component
            ->assertSet('entityTrustReviewIsConfigurable', false)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', true)
            ->assertSet("entityTrustRelinkParentSkuByProduct.{$product->id}", null);
    }

    #[Test]
    public function configurable_explicit_relink_requires_merchant_parent_sku_and_forwards_it(): void
    {
        [$account, $product,, $parentSku] = $this->seedConfigurableRelinkRequiredFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('entityTrustWorkingSet.0.available_action', 'relink')
            ->set("entityTrustRelinkParentSkuByProduct.{$product->id}", $parentSku)
            ->call('requestEntityTrustRelink', (string) $product->id);

        $component
            ->assertSet('entityTrustReviewIsConfigurable', true)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', true)
            ->assertSet("entityTrustRelinkParentSkuByProduct.{$product->id}", $parentSku);
    }

    #[Test]
    public function parent_sku_inputs_are_scoped_per_product_row(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareConfigurableEntityTrustConfiguration($account);

        [$productA, $variantsA] = $this->createConfigurableEntityTrustProduct($account->workspace, 'CORR-CFG-A', 'CORR-PARENT-A');
        [$productB, $variantsB] = $this->createConfigurableEntityTrustProduct($account->workspace, 'CORR-CFG-B', 'CORR-PARENT-B');

        $this->responder->registerProduct('CORR-PARENT-A', 9701, 'configurable');
        $this->responder->registerProduct('CORR-PARENT-B', 9702, 'configurable');

        foreach ($variantsA as $i => $variant) {
            $this->responder->registerProduct($variant->sku, 9710 + $i, 'simple');
        }

        foreach ($variantsB as $i => $variant) {
            $this->responder->registerProduct($variant->sku, 9720 + $i, 'simple');
        }

        $this->responder->registerConfigurableChildren('CORR-PARENT-A', [
            ['sku' => $variantsA[0]->sku, 'id' => 9710, 'type_id' => 'simple'],
            ['sku' => $variantsA[1]->sku, 'id' => 9711, 'type_id' => 'simple'],
        ]);
        $this->responder->registerConfigurableChildren('CORR-PARENT-B', [
            ['sku' => $variantsB[0]->sku, 'id' => 9720, 'type_id' => 'simple'],
            ['sku' => $variantsB[1]->sku, 'id' => 9721, 'type_id' => 'simple'],
        ]);

        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set("entityTrustInitialLinkParentSkuByProduct.{$productA->id}", 'CORR-PARENT-A')
            ->set("entityTrustInitialLinkParentSkuByProduct.{$productB->id}", 'CORR-PARENT-B')
            ->call('requestEntityTrustReview', (string) $productA->id)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', true)
            ->assertSet("entityTrustInitialLinkParentSkuByProduct.{$productA->id}", 'CORR-PARENT-A')
            ->assertSet("entityTrustInitialLinkParentSkuByProduct.{$productB->id}", 'CORR-PARENT-B')
            ->call('cancelEntityTrustFlow');

        $component
            ->assertSet("entityTrustInitialLinkParentSkuByProduct.{$productA->id}", null)
            ->assertSet("entityTrustInitialLinkParentSkuByProduct.{$productB->id}", 'CORR-PARENT-B')
            ->call('requestEntityTrustReview', (string) $productB->id)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', true)
            ->assertSet('entityTrustOutcomeProductName', $productB->name);
    }

    #[Test]
    public function initial_link_required_product_rejects_crafted_relink_action_before_backend_review(): void
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture('CORR-ACTION-REVIEW-ONLY-SKU', 7011);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('entityTrustWorkingSet.0.available_action', 'review')
            ->call('requestEntityTrustRelink', (string) $product->id)
            ->assertSet('entityTrustReviewFlowId', null)
            ->assertSet('entityTrustOutcomeCategory', null)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', false);

        $this->assertSame(0, ExternalRecordLink::withoutWorkspaceScope()->where('product_variant_id', $variant->id)->count());
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function already_confirmed_product_rejects_crafted_relink_action_without_mutating_existing_trust(): void
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture('CORR-ACTION-NONE-SKU', 7012);
        $actor = $this->createEntityTrustActor($account->workspace);

        $existingLink = $this->createMerchantConfirmedLinkForProduct($product, $variant, '7012', 'CORR-ACTION-NONE-SKU');

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('entityTrustWorkingSet.0.available_action', 'none')
            ->call('requestEntityTrustRelink', (string) $product->id)
            ->assertSet('entityTrustReviewFlowId', null)
            ->assertSet('entityTrustOutcomeCategory', null)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', false);

        $this->assertDatabaseHas('external_record_links', [
            'id' => $existingLink->id,
            'external_identifier' => 'CORR-ACTION-NONE-SKU',
            'external_record_discriminator' => '7012',
        ]);
        $this->assertSame(1, ExternalRecordLink::withoutWorkspaceScope()->where('product_variant_id', $variant->id)->count());
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function relink_review_required_product_rejects_crafted_review_action_before_normal_review_flow(): void
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture('CORR-ACTION-RELINK-SKU', 7013);
        $actor = $this->createEntityTrustActor($account->workspace);

        $this->createMerchantConfirmedLinkForProduct($product, $variant, '7013', 'CORR-ACTION-RELINK-SKU');
        $this->createMerchantConfirmedLinkForProduct($product, $variant, '7713', 'CORR-ACTION-RELINK-OTHER-SKU');

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('entityTrustWorkingSet.0.available_action', 'relink')
            ->call('requestEntityTrustReview', (string) $product->id)
            ->assertSet('entityTrustReviewFlowId', null)
            ->assertSet('entityTrustOutcomeCategory', null)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', false);

        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    // -----------------------------------------------------------------
    // Extra-children 3-state preservation (Fix #4, #8)
    // -----------------------------------------------------------------

    #[Test]
    public function extra_children_available_and_non_empty_renders_warning_state(): void
    {
        [$account, $product, $variants, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set("entityTrustInitialLinkParentSkuByProduct.{$product->id}", $parentSku)
            ->call('requestEntityTrustReview', (string) $product->id);

        $component
            ->assertSet('entityTrustActiveExtraChildrenAvailable', true)
            ->assertCount('entityTrustActiveExtraChildSkus', 1)
            ->assertSee('data-extra-children-state="available-non-empty"', false);
    }

    #[Test]
    public function extra_children_available_and_empty_renders_empty_state(): void
    {
        [$account, $product, $variants, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        // Remove the extra child from the responder.
        $this->responder->registerConfigurableChildren($parentSku, [
            ['sku' => $variants[0]->sku, 'id' => 8000, 'type_id' => 'simple'],
            ['sku' => $variants[1]->sku, 'id' => 8001, 'type_id' => 'simple'],
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set("entityTrustInitialLinkParentSkuByProduct.{$product->id}", $parentSku)
            ->call('requestEntityTrustReview', (string) $product->id);

        $component
            ->assertSet('entityTrustActiveExtraChildrenAvailable', true)
            ->assertCount('entityTrustActiveExtraChildSkus', 0)
            ->assertSee('data-extra-children-state="available-empty"', false)
            ->assertSee(__('entity_trust.extra_children.empty_notice'), false);
    }

    #[Test]
    public function extra_children_unavailable_renders_unavailable_state(): void
    {
        [$account, $product, $variants, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        $this->responder->failConfigurableChildrenLookup($parentSku, 500);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set("entityTrustInitialLinkParentSkuByProduct.{$product->id}", $parentSku)
            ->call('requestEntityTrustReview', (string) $product->id);

        $component
            ->assertSet('entityTrustActiveExtraChildrenAvailable', false)
            ->assertSee('data-extra-children-state="unavailable"', false)
            ->assertSee(__('entity_trust.extra_children.unavailable_notice'), false);
    }

    // -----------------------------------------------------------------
    // Stale / invalid / mismatched review evidence (Fix #8)
    // -----------------------------------------------------------------

    #[Test]
    public function stale_remote_review_returns_remote_changed_failure_category(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-STALE-REMOTE-SKU', 7020);
        $actor = $this->createEntityTrustActor($account->workspace);

        // First review establishes a flow.
        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id);

        $flowId = $component->get('entityTrustReviewFlowId');
        $this->assertNotNull($flowId);

        // Simulate a remote change by remapping the entity id. The backend
        // R2b-1 reviewer will see the new logical id and reject with
        // RemoteChangedSinceReview.
        $this->responder->remapLogicalEntityId('CORR-STALE-REMOTE-SKU', 99001);

        $component->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'stale_review')
            ->assertSet('entityTrustActiveReviewFlowId', null);

        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function local_fingerprint_change_preserves_flow_until_confirm_and_returns_security(): void
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture('CORR-STALE-LOCAL-SKU', 7021);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id);

        $flowId = $component->get('entityTrustReviewFlowId');
        $this->assertNotNull($flowId);
        $this->assertSame((string) $product->id, $component->get('entityTrustProductId'));
        $this->assertNotNull($this->getFlowEntry($flowId), 'Flow entry must exist before local-fingerprint mutation.');

        PriceListItem::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace->id)
            ->where('product_variant_id', $variant->id)
            ->where('quantity_min', 1)
            ->update(['price' => 149.00]);

        $this->assertNotNull($this->getFlowEntry($flowId), 'Flow entry must survive local-fingerprint mutation before confirm.');

        $component->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'security')
            ->assertSet('entityTrustOutcomeExplanation', __('entity_trust.failure.invalid_review_evidence.explanation'))
            ->assertSet('entityTrustActiveReviewFlowId', null);
    }

    #[Test]
    public function expired_or_invalid_review_evidence_returns_confirmation_expired_failure(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-EXPIRED-SKU', 7022);
        $actor = $this->createEntityTrustActor($account->workspace);

        // Run the real review flow so the component holds the right
        // productId + flowId binding. Then drop the underlying flow before
        // confirm so the orchestrator must fail closed with a stale_review
        // outcome (the flow is gone, but entityTrustProductId +
        // entityTrustReviewFlowId are still pointing at it server-side).
        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id);

        $flowId = $component->get('entityTrustReviewFlowId');
        $this->assertNotNull($flowId);

        app(EntityTrustReviewFlowStore::class)->discard($flowId);

        $component->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'stale_review')
            ->assertSet('entityTrustActiveReviewFlowId', null);

        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function review_target_mismatch_is_rejected_at_confirm(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-MISMATCH-SKU', 7023);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id);

        $this->assertNotNull($component->get('entityTrustReviewFlowId'));

        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);

        app(ConnectorAccountSettingsService::class)->update(
            $admin,
            $account->workspace,
            $account->id,
            UpdateConnectorAccountInput::adobePaas(
                baseUrl: 'https://target-shift.example.test',
                storeCode: (string) $account->store_code,
                tenantContext: $account->tenant_context,
                credentialMutation: CredentialMutation::keep(),
            ),
        );

        $component->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'stale_review')
            ->assertSet('entityTrustActiveReviewFlowId', null);
    }

    // -----------------------------------------------------------------
    // Account configuration & link-state failures (Fix #8)
    // -----------------------------------------------------------------

    #[Test]
    public function configuration_revision_change_preserves_flow_until_confirm_and_returns_security(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-ACCTCFG-SKU', 7030);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id);

        $flowId = $component->get('entityTrustReviewFlowId');
        $this->assertNotNull($flowId);
        $this->assertSame((string) $product->id, $component->get('entityTrustProductId'));
        $this->assertNotNull($this->getFlowEntry($flowId), 'Flow entry must exist before configuration drift.');

        $configuration = app(SyncConfigurationLookupService::class)->findProductsDefaultContext($account);
        $this->assertNotNull($configuration);
        $configuration->forceFill(['configuration_revision' => 'rev-drift-'.uniqid()])->save();

        $this->assertNotNull($this->getFlowEntry($flowId), 'Flow entry must survive configuration drift before confirm.');

        $component->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'security')
            ->assertSet('entityTrustOutcomeExplanation', __('entity_trust.failure.invalid_review_evidence.explanation'))
            ->assertSet('entityTrustActiveReviewFlowId', null);

        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function direct_backend_local_fingerprint_drift_returns_invalid_review_evidence(): void
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture('CORR-DIRECT-LOCAL-SKU', 7038);
        $actor = $this->createEntityTrustActor($account->workspace);

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        PriceListItem::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace->id)
            ->where('product_variant_id', $variant->id)
            ->where('quantity_min', 1)
            ->update(['price' => 149.00]);

        try {
            app(AdobeProductEntityTrustConfirmationService::class)->confirm(
                $actor,
                $account->workspace,
                $account->id,
                (string) $product->id,
                $review->reviewToken,
            );
            $this->fail('Expected InvalidReviewEvidence for direct local-fingerprint drift.');
        } catch (EntityTrustException $exception) {
            $this->assertSame(EntityTrustFailureReason::InvalidReviewEvidence, $exception->reason);
        }
    }

    #[Test]
    public function direct_backend_configuration_revision_drift_returns_invalid_review_evidence(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-DIRECT-CONFIG-SKU', 7039);
        $actor = $this->createEntityTrustActor($account->workspace);

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        $configuration = app(SyncConfigurationLookupService::class)->findProductsDefaultContext($account);
        $this->assertNotNull($configuration);
        $configuration->forceFill(['configuration_revision' => 'rev-direct-'.uniqid()])->save();

        try {
            app(AdobeProductEntityTrustConfirmationService::class)->confirm(
                $actor,
                $account->workspace,
                $account->id,
                (string) $product->id,
                $review->reviewToken,
            );
            $this->fail('Expected InvalidReviewEvidence for direct configuration revision drift.');
        } catch (EntityTrustException $exception) {
            $this->assertSame(EntityTrustFailureReason::InvalidReviewEvidence, $exception->reason);
        }
    }

    #[Test]
    public function link_collision_rejects_confirm_with_identity_conflict_category(): void
    {
        [$account, $productA, $variantA] = $this->seedSimpleReadyFixture('CORR-COLLIDE-SKU', 7031);
        // Second product, with a confirmed link already using the same
        // Magento SKU as productA. The R2b-1 persister detects a collision
        // when we try to confirm the link for productA and surfaces
        // LinkCollision (mapped to identity_conflict).
        [, $productB, $variantB] = $this->seedSimpleReadyFixture('CORR-COLLIDE-OTHER-SKU', 7031);
        $actor = $this->createEntityTrustActor($account->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $account->workspace,
                $account->id,
                $variantB,
                'CORR-COLLIDE-SKU',          // colliding external identifier
                '9999',                      // arbitrary discriminator
                $this->createWorkspaceActor($account->workspace),
            ),
        );

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $productA->id)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', true)
            ->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'identity_conflict');

        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function ambiguous_existing_link_rejects_confirm_with_identity_conflict_category(): void
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture('CORR-AMBI-SKU', 7032);
        $actor = $this->createEntityTrustActor($account->workspace);

        // Mirror the canonical R2b-1 setup: same local variant, same
        // discriminator, different legacy external identifier.
        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'OTHER-LEGACY-SKU',
            'external_record_discriminator' => '7032',
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', true)
            ->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'identity_conflict');
    }

    // -----------------------------------------------------------------
    // Foreign workspace / account / product fail-closed (Fix #8)
    // -----------------------------------------------------------------

    #[Test]
    public function foreign_workspace_review_fails_closed(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-FOREIGN-WS-SKU', 7040);
        $actor = $this->createEntityTrustActor($account->workspace);

        $foreignWorkspace = Workspace::query()->where('is_default', false)->first()
            ?? Workspace::query()->create([
                'name' => 'Foreign',
                'slug' => 'foreign-'.uniqid(),
                'is_default' => false,
            ]);

        $foreignProduct = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $foreignWorkspace->id,
            'onec_guid' => 'FOREIGN-'.uniqid(),
            'sku' => 'FOREIGN-'.uniqid(),
            'name' => 'Foreign',
            'is_active' => true,
        ]);

        // The Livewire must refuse a product that lives in another workspace
        // (the working set never contains it). The product-not-found branch
        // renders the safe error and the outcome is not ReadyForConfirmation.
        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $foreignProduct->id);

        $component
            ->assertSet('entityTrustOutcomeReadyForConfirmation', false)
            ->assertSet('entityTrustOutcomeCategory', null);

        // The error title was set to a safe, product-not-found copy.
        $this->assertNotNull($component->get('entityTrustErrorTitle'));
    }

    #[Test]
    public function out_of_projection_product_review_fails_closed(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-FOREIGN-ACC-SKU', 7041);
        $actor = $this->createEntityTrustActor($account->workspace);

        $inactiveProduct = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace->id,
            'onec_guid' => 'INACTIVE-'.uniqid(),
            'sku' => 'INACTIVE-'.uniqid(),
            'name' => 'Inactive',
            'is_active' => false,
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $inactiveProduct->id);

        $component
            ->assertSet('entityTrustOutcomeReadyForConfirmation', false)
            ->assertSet('entityTrustOutcomeCategory', null);

        $this->assertNotNull($component->get('entityTrustErrorTitle'));
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    // -----------------------------------------------------------------
    // Atomic concurrent flow consumption (Fix #5, #8)
    // -----------------------------------------------------------------

    #[Test]
    public function lock_contention_fails_closed_and_consumed_flows_are_single_use(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-ATOMIC-SKU', 7050);
        $actor = $this->createEntityTrustActor($account->workspace);

        // Use a file-backed cache so the lock provider (which the array
        // driver also implements, but file is easier to reason about) is
        // exercised exactly the way it will be exercised in production
        // (the configured CACHE_STORE in phpunit.xml is `array`; for the
        // atomicity proof we need a real lock-provider-backed store).
        $cacheDir = sys_get_temp_dir().'/entity_trust_atomic_'.uniqid();
        @mkdir($cacheDir, 0700, true);
        config(['cache.default' => 'file']);
        config(['cache.stores.file' => ['driver' => 'file', 'path' => $cacheDir]]);
        $this->app->forgetInstance(\Illuminate\Contracts\Cache\Repository::class);
        $this->app->forgetInstance(Repository::class);
        $this->app->forgetInstance(EntityTrustReviewFlowStore::class);
        $this->app->singleton(\Illuminate\Contracts\Cache\Repository::class, fn () => Cache::store('file'));

        $store = app(EntityTrustReviewFlowStore::class);
        $workspace = $account->workspace;

        $flowId = $store->issue(
            $actor,
            $workspace,
            $account->id,
            (string) $product->id,
            'atomic-test-token',
            EntityTrustConfirmationMode::SimpleVariant,
            false,
            null,
            false,
        );

        // Step 1: a competitor that holds the cache lock can prevent the
        // first consume() from succeeding. The cache lock provider is the
        // same one used by consume() — this is a deterministic proof
        // that the lock is the single point of arbitration.
        $lock = Cache::store('file')
            ->lock('entity_trust_review_flow_lock:entity_trust_review_flow:'.$flowId, 5);
        $this->assertTrue($lock->get(), 'Competitor must acquire the lock to set up the test.');

        $loser = $store->consume($actor, $workspace, $account->id, (string) $product->id, $flowId);
        $this->assertNull($loser, 'consume() must fail closed while a competitor holds the lock.');

        // Step 2: release the lock. The same consume() now succeeds and
        // returns the payload.
        $lock->release();

        $winner = $store->consume($actor, $workspace, $account->id, (string) $product->id, $flowId);
        $this->assertNotNull($winner, 'consume() must succeed once the lock is released.');
        $this->assertSame('atomic-test-token', $winner->reviewToken);

        // Step 3: the data is single-use. A second consume() must fail
        // closed even though the lock is free.
        $replay = $store->consume($actor, $workspace, $account->id, (string) $product->id, $flowId);
        $this->assertNull($replay, 'A consumed flow id must never be replayed.');

        // Cleanup.
        Cache::store('file')->flush();
        @rmdir($cacheDir);
    }

    // -----------------------------------------------------------------
    // Token security (Fix #8)
    // -----------------------------------------------------------------

    #[Test]
    public function valid_unconsumed_flow_fails_closed_on_binding_mismatch_before_any_successful_consume(): void
    {
        [$account, $productA] = $this->seedSimpleReadyFixture('CORR-FLOW-A-SKU', 7055);
        [, $productB] = $this->seedSimpleReadyFixture('CORR-FLOW-B-SKU', 7056);
        $actor = $this->createEntityTrustActor($account->workspace);

        $flowId = app(EntityTrustReviewFlowStore::class)->issue(
            $actor,
            $account->workspace,
            $account->id,
            (string) $productA->id,
            'binding-test-token',
            EntityTrustConfirmationMode::SimpleVariant,
            false,
            null,
            false,
        );

        $payload = app(EntityTrustReviewFlowStore::class)->consume(
            $actor,
            $account->workspace,
            $account->id,
            (string) $productB->id,
            $flowId,
        );

        $this->assertNull($payload);

        $replay = app(EntityTrustReviewFlowStore::class)->consume(
            $actor,
            $account->workspace,
            $account->id,
            (string) $productA->id,
            $flowId,
        );

        $this->assertNull($replay);
    }

    #[Test]
    public function review_token_and_internal_keys_never_appear_in_html_or_dehydrated_state(): void
    {
        [$account, $product,, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set("entityTrustInitialLinkParentSkuByProduct.{$product->id}", $parentSku)
            ->call('requestEntityTrustReview', (string) $product->id);

        $dom = (string) $component->html();
        $serialized = json_encode($component->snapshot, JSON_THROW_ON_ERROR);

        foreach ([
            'reviewToken',
            'explicitRelink',
            'explicit_relink',
            'logical_entity_id',
            'entity_id',
            'external_record_discriminator',
            'remote_fingerprint',
            'subject_key',
            'field_key',
            'target_snapshot',
            'raw_payload',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dom, "HTML must not contain '{$forbidden}'.");
            $this->assertStringNotContainsString($forbidden, $serialized, "Dehydrated state must not contain '{$forbidden}'.");
        }
    }

    #[Test]
    public function remote_media_unknown_is_not_rendered_as_store_media(): void
    {
        [$account, $product,, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set("entityTrustInitialLinkParentSkuByProduct.{$product->id}", $parentSku)
            ->call('requestEntityTrustReview', (string) $product->id)
            ->assertDontSee('Media on store', false)
            ->assertDontSee('Медіа в магазині', false)
            ->assertDontSee('Медиа в магазине', false);
    }

    #[Test]
    public function successful_confirmation_copy_does_not_claim_the_product_is_ready_for_live_transfer(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-SUCCESS-COPY-SKU', 7057);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id)
            ->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'success');

        $component->assertDontSee('ready to transfer', false)
            ->assertDontSee('готов к передаче', false)
            ->assertDontSee('готовий до передачі', false)
            ->assertSee(__('entity_trust.failure.confirmation_completed.explanation'), false);
    }

    #[Test]
    public function no_consequential_adobe_write_occurs_under_any_merchant_path(): void
    {
        [$account, $product,, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set("entityTrustInitialLinkParentSkuByProduct.{$product->id}", $parentSku)
            ->call('requestEntityTrustReview', (string) $product->id)
            ->call('confirmEntityTrust')
            ->call('requestEntityTrustRelink', (string) $product->id)
            ->set("entityTrustRelinkParentSkuByProduct.{$product->id}", $parentSku)
            ->call('requestEntityTrustRelink', (string) $product->id)
            ->call('cancelEntityTrustFlow');

        $this->assertFalse(
            $this->responder->hasConsequentialWrite(),
            'No POST/PUT/PATCH/DELETE may ever hit Magento from R2b-2 merchant surface.',
        );
    }

    #[Test]
    public function live_support_remains_disabled_in_r2b2_correction_pass(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-LIVE-OFF-SKU', 7060);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id]);

        // The Live read model exposes liveSupportAvailable = false in
        // R2b-2 — Live is gated behind a feature flag that remains off.
        $this->assertFalse((bool) $component->get('liveSupportAvailable'));
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

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

    /**
     * @return array{0: ConnectorAccount, 1: Product, 2: list<ProductVariant>, 3: string}
     */
    private function seedConfigurableReadyFixture(): array
    {
        $account = $this->createConnectorAccount();
        $this->prepareConfigurableEntityTrustConfiguration($account);
        [$product, $variants] = $this->createConfigurableEntityTrustProduct($account->workspace, 'CORR-CFG-SKU', 'CORR-MERCHANT-PARENT');

        $this->responder->registerProduct('CORR-MERCHANT-PARENT', 9777, 'configurable');
        foreach ($variants as $i => $variant) {
            $this->responder->registerProduct($variant->sku, 9000 + $i, 'simple');
        }
        $this->responder->registerConfigurableChildren('CORR-MERCHANT-PARENT', [
            ['sku' => $variants[0]->sku, 'id' => 9000, 'type_id' => 'simple'],
            ['sku' => $variants[1]->sku, 'id' => 9001, 'type_id' => 'simple'],
            ['sku' => 'CORR-EXTRA-CHILD', 'id' => 9002, 'type_id' => 'simple'],
        ]);

        return [$account, $product, $variants, 'CORR-MERCHANT-PARENT'];
    }

    /**
     * @return array{0: ConnectorAccount, 1: Product, 2: ProductVariant}
     */
    private function seedSimpleRelinkRequiredFixture(string $sku, int $logicalEntityId): array
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture($sku, $logicalEntityId);

        $this->createMerchantConfirmedLinkForProduct($product, $variant, (string) $logicalEntityId, $sku);
        $this->createMerchantConfirmedLinkForProduct($product, $variant, (string) ($logicalEntityId + 5000), $sku.'-ALT');

        return [$account, $product, $variant];
    }

    /**
     * @return array{0: ConnectorAccount, 1: Product, 2: list<ProductVariant>, 3: string}
     */
    private function seedConfigurableRelinkRequiredFixture(): array
    {
        [$account, $product, $variants, $parentSku] = $this->seedConfigurableReadyFixture();

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $account->workspace,
                $account->id,
                $product,
                $parentSku,
                '9777',
                $this->createWorkspaceActor($account->workspace),
            ),
        );
        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $account->workspace,
                $account->id,
                $product,
                'CORR-OTHER-PARENT',
                '9778',
                $this->createWorkspaceActor($account->workspace),
            ),
        );

        return [$account, $product, $variants, $parentSku];
    }

    private function createMerchantConfirmedLinkForProduct(
        Product $product,
        ProductVariant $variant,
        string $discriminator,
        string $externalIdentifier,
    ): ExternalRecordLink {
        $attributes = $this->merchantConfirmedVariantLinkAttributes(
            $product->workspace,
            (string) ConnectorAccount::query()->where('workspace_id', $product->workspace_id)->value('id'),
            $variant,
            $externalIdentifier,
            $discriminator,
        );

        return ExternalRecordLink::withoutWorkspaceScope()->create($attributes);
    }

    private function getFlowEntry(string $flowId): ?EntityTrustReviewFlowPayload
    {
        $entry = Cache::store()->get($this->reviewFlowCacheKey($flowId));

        return $entry instanceof EntityTrustReviewFlowPayload ? $entry : null;
    }

    private function reviewFlowCacheKey(string $flowId): string
    {
        return 'entity_trust_review_flow:'.$flowId;
    }
}
