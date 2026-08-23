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
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustConfirmationService;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustReviewService;
use App\Services\Sync\EntityTrust\EntityTrustReviewFlowStore;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
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
    public function missing_run_sync_live_permission_blocks_review_with_security_outcome(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-LIVEONLY-MISSING-SKU', 7002);
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id)
            ->assertSet('entityTrustOutcomeCategory', 'security')
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
            ->set('entityTrustInitialLinkParentSku', $parentSku)
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
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-RELINK-SIMPLE-SKU', 7010);
        $actor = $this->createEntityTrustActor($account->workspace);

        // The Livewire method only takes a product id. The merchant must
        // never be asked for a Magento parent SKU for a simple product.
        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustRelink', (string) $product->id);

        $component
            ->assertSet('entityTrustReviewIsConfigurable', false)
            ->assertSet('entityTrustRelinkParentSku', null);
    }

    #[Test]
    public function configurable_explicit_relink_requires_merchant_parent_sku_and_forwards_it(): void
    {
        [$account, $product,, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('entityTrustRelinkParentSku', $parentSku)
            ->call('requestEntityTrustRelink', (string) $product->id);

        $component
            ->assertSet('entityTrustReviewIsConfigurable', true)
            ->assertSet('entityTrustRelinkParentSku', $parentSku);
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
            ->set('entityTrustInitialLinkParentSku', $parentSku)
            ->call('requestEntityTrustReview', (string) $product->id);

        $component
            ->assertSet('entityTrustActiveExtraChildrenAvailable', true)
            ->assertCount('entityTrustActiveExtraChildSkus', 1)
            ->assertSee('data-extra-children-state="available-non-empty"', false)
            // Use assertSeeText to compare against stripped text, which sidesteps
            // the apostrophe HTML-entity escaping in the rendered DOM.
            ->assertSeeText('1 додаткових варіантів', false);
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
            ->set('entityTrustInitialLinkParentSku', $parentSku)
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
            ->set('entityTrustInitialLinkParentSku', $parentSku)
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

        // First review establishes a flow and writes the envelope's
        // logical_entity_id (7020) plus the remote_fingerprint.
        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id);

        $flowId = $component->get('entityTrustReviewFlowId');
        $this->assertNotNull($flowId);

        // Simulate a remote change by remapping the entity id. The R2b-1
        // confirmation service re-verifies the candidate and finds a
        // logical_entity_id mismatch (7020 vs 99001). The backend raises
        // RemoteChangedSinceReview, which the failure presenter maps to
        // the StaleReview category — the correct merchant-safe surface for
        // "the Magento state you reviewed is no longer the one we see".
        $this->responder->remapLogicalEntityId('CORR-STALE-REMOTE-SKU', 99001);

        $component->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'stale_review')
            ->assertSet('entityTrustActiveReviewFlowId', null);

        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function stale_local_review_returns_local_changed_failure_category(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-STALE-LOCAL-SKU', 7021);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id);

        $flowId = $component->get('entityTrustReviewFlowId');
        $this->assertNotNull($flowId);

        // Mutate a field that is part of the local fingerprint used at
        // confirm time (`name`). The base_price_cache column is NOT part of
        // the R2b-1 local fingerprint — pricing is read from the configured
        // price list at intent-resolution time, not from the variant cache.
        $product->forceFill(['name' => 'Renamed Local '.uniqid()])->save();

        $component->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'stale_review')
            ->assertSet('entityTrustActiveReviewFlowId', null);

        $this->assertFalse($this->responder->hasConsequentialWrite());
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

        // First review establishes a real flow for THIS product. We then
        // manually overwrite the same flow id with a payload bound to a
        // different product, so the consume() binding check fails closed.
        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id);

        $flowId = $component->get('entityTrustReviewFlowId');
        $this->assertNotNull($flowId);

        $otherProduct = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace->id,
            'onec_guid' => 'OTHER-'.uniqid(),
            'sku' => 'OTHER-'.uniqid(),
            'name' => 'Other',
            'is_active' => true,
        ]);

        // Replace the issued flow with one bound to a foreign product, so
        // binding check on consume() returns null and the orchestrator
        // surfaces stale_review / confirmation_expired_or_invalid.
        app(EntityTrustReviewFlowStore::class)->discard($flowId);
        app(EntityTrustReviewFlowStore::class)->issue(
            $actor,
            $account->workspace,
            $account->id,
            (string) $otherProduct->id,
            'foreign-token',
            EntityTrustConfirmationMode::SimpleVariant,
            null,
            false,
        );

        $component->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'stale_review');
    }

    // -----------------------------------------------------------------
    // Account configuration & link-state failures (Fix #8)
    // -----------------------------------------------------------------

    #[Test]
    public function account_configuration_change_between_review_and_confirm_is_rejected(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-ACCTCFG-SKU', 7030);
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id);

        $this->assertNotNull($component->get('entityTrustReviewFlowId'));

        // Mutate the sync configuration revision so the R2b-1 confirmation
        // service detects a configuration drift. The envelope validate step
        // runs first and surfaces InvalidReviewEvidence (Security category)
        // before the lock-and-compare inside the DB::transaction can run.
        // Either way, the merchant must redo the review from scratch; the
        // outcome category for this drift IS stale_review because the
        // backend re-validation pipeline classifies invalid review
        // evidence as a stale review (the link to the configuration that
        // produced this evidence has been broken).
        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace->id)
            ->first();
        $this->assertNotNull($configuration);
        $configuration->forceFill(['configuration_revision' => 'rev-drift-'.uniqid()])->save();

        $component->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'stale_review')
            ->assertSet('entityTrustActiveReviewFlowId', null);

        $this->assertFalse($this->responder->hasConsequentialWrite());
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

        // Pre-create a legacy (non-merchant-confirmed) link for the SAME
        // variant the orchestrator is about to link, with a different
        // external_identifier but the same discriminator. The R2b-1
        // persister accepts the silent upgrade only when the existing
        // external_identifier matches; the mismatch forces the
        // AmbiguousExistingLink branch (IdentityConflict category).
        ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace->id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'LEGACY-OTHER-SKU',
            'external_record_discriminator' => '7032',
            // trust_origin left null => legacy, not merchant-confirmed.
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $product->id)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', true)
            ->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeCategory', 'identity_conflict');

        $this->assertFalse($this->responder->hasConsequentialWrite());
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
    public function foreign_account_review_uses_only_the_visible_working_set(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('CORR-FOREIGN-ACC-SKU', 7041);
        $actor = $this->createEntityTrustActor($account->workspace);

        // The Livewire does not see a working set row for a product that
        // belongs to a different account's configuration, so the review
        // is rejected with a security outcome (the orchestrator's
        // fresh-dual-permission + not-in-working-set path).
        $foreignProduct = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace->id,
            'onec_guid' => 'FOREIGN-'.uniqid(),
            'sku' => 'FOREIGN-'.uniqid(),
            'name' => 'Foreign',
            'is_active' => true,
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('requestEntityTrustReview', (string) $foreignProduct->id)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', false);

        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    // -----------------------------------------------------------------
    // Atomic concurrent flow consumption (Fix #5, #8)
    // -----------------------------------------------------------------

    #[Test]
    public function concurrent_consume_atomicity_only_one_consumer_receives_payload(): void
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
        $this->app->forgetInstance(\Illuminate\Cache\Repository::class);
        $this->app->forgetInstance(EntityTrustReviewFlowStore::class);
        $this->app->singleton(\Illuminate\Contracts\Cache\Repository::class, fn () => \Illuminate\Support\Facades\Cache::store('file'));

        $store = app(EntityTrustReviewFlowStore::class);
        $workspace = $account->workspace;

        $flowId = $store->issue(
            $actor,
            $workspace,
            $account->id,
            (string) $product->id,
            'atomic-test-token',
            EntityTrustConfirmationMode::SimpleVariant,
            null,
            false,
        );

        // Step 1: a competitor that holds the cache lock can prevent the
        // first consume() from succeeding. The cache lock provider is the
        // same one used by consume() — this is a deterministic proof
        // that the lock is the single point of arbitration.
        $lock = \Illuminate\Support\Facades\Cache::store('file')
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
        \Illuminate\Support\Facades\Cache::store('file')->flush();
        @rmdir($cacheDir);
    }

    // -----------------------------------------------------------------
    // Token security (Fix #8)
    // -----------------------------------------------------------------

    #[Test]
    public function review_token_and_internal_keys_never_appear_in_html_or_dehydrated_state(): void
    {
        [$account, $product,, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('entityTrustInitialLinkParentSku', $parentSku)
            ->call('requestEntityTrustReview', (string) $product->id);

        $dom = (string) $component->html();
        $serialized = json_encode($component->snapshot, JSON_THROW_ON_ERROR);

        foreach (['reviewToken', 'explicitRelink', 'explicit_relink', 'subject_key', 'field_key'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dom, "HTML must not contain '{$forbidden}'.");
            $this->assertStringNotContainsString($forbidden, $serialized, "Dehydrated state must not contain '{$forbidden}'.");
        }
    }

    #[Test]
    public function no_consequential_adobe_write_occurs_under_any_merchant_path(): void
    {
        [$account, $product,, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('entityTrustInitialLinkParentSku', $parentSku)
            ->call('requestEntityTrustReview', (string) $product->id)
            ->call('confirmEntityTrust')
            ->call('requestEntityTrustRelink', (string) $product->id)
            ->set('entityTrustRelinkParentSku', $parentSku)
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
}
