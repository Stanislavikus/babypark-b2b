<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncPreviewFindingCode;
use App\Enums\SyncPreviewOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Filament\Pages\Sync\ListSyncDataSetup;
use App\Filament\Pages\Sync\ManageAdobeProductsExportPreview;
use App\Models\ConnectorAccount;
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
use App\Support\Connectors\ConnectorAuthorization;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Preview\SyncPreviewFinding;
use App\Support\Sync\SyncExternalContext;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class Stage2A2MerchantPreviewWorkSurfaceTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import],
            [SyncDataDomain::Products, SyncSemanticOperation::Export],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function preview_only_actor_discovers_data_setup_landing_with_preview_action_only(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        Livewire::actingAs($actor)
            ->test(ListSyncDataSetup::class)
            ->assertOk()
            ->assertSee(__('sync_preview.actions.open_preview'))
            ->assertDontSee(__('sync_data_setup.page.open_setup'))
            ->assertSee('data-testid="sync-data-setup-open-preview-'.$account->id.'"', false);
    }

    #[Test]
    public function setup_only_actor_sees_setup_but_not_preview_on_landing(): void
    {
        $workspace = $this->defaultWorkspace();
        $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);

        Livewire::actingAs($actor)
            ->test(ListSyncDataSetup::class)
            ->assertOk()
            ->assertSee(__('sync_data_setup.page.open_setup'))
            ->assertDontSee(__('sync_preview.actions.open_preview'));
    }

    #[Test]
    public function actor_with_both_permissions_sees_setup_and_preview_actions(): void
    {
        $workspace = $this->defaultWorkspace();
        $this->adobeAccount($workspace);
        $actor = $this->actorWithPermissions($workspace, [
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        ]);

        Livewire::actingAs($actor)
            ->test(ListSyncDataSetup::class)
            ->assertOk()
            ->assertSee(__('sync_data_setup.page.open_setup'))
            ->assertSee(__('sync_preview.actions.open_preview'));
    }

    #[Test]
    public function actor_with_neither_permission_cannot_reach_landing(): void
    {
        $workspace = $this->defaultWorkspace();
        $this->adobeAccount($workspace);
        $actor = User::factory()->create(['is_active' => true]);
        $this->makeWorkspaceMembership($workspace, $actor);

        Livewire::actingAs($actor)
            ->test(ListSyncDataSetup::class)
            ->assertForbidden();
    }

    #[Test]
    public function preview_permission_does_not_grant_connector_account_safe_read(): void
    {
        $workspace = $this->defaultWorkspace();
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $this->assertFalse(app(ConnectorAuthorization::class)->canSafeRead($actor, $workspace));
    }

    #[Test]
    public function preview_page_works_without_connector_read_permission(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertOk()
            ->assertSee(__('sync_preview.page.title'));
    }

    #[Test]
    public function forged_foreign_account_fails_closed_on_preview_page(): void
    {
        $workspace = $this->defaultWorkspace();
        $foreign = Workspace::query()->create(['name' => 'Foreign', 'is_default' => false]);
        $foreignAccount = $this->adobeAccount($foreign);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $foreignAccount->id])
            ->assertForbidden();
    }

    #[Test]
    public function sync_support_profile_without_preview_mode_hides_preview_action(): void
    {
        $workspace = $this->defaultWorkspace();
        $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        Livewire::actingAs($actor)
            ->test(ListSyncDataSetup::class)
            ->assertOk()
            ->assertDontSee(__('sync_preview.actions.open_preview'));
    }

    #[Test]
    public function preview_page_read_does_not_mutate_configuration_or_create_run(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);
        $configurationCount = SyncConfiguration::withoutWorkspaceScope()->count();
        $runCount = SyncRun::withoutWorkspaceScope()->count();

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('refreshPresentation')
            ->assertOk();

        $this->assertSame($configurationCount, SyncConfiguration::withoutWorkspaceScope()->count());
        $this->assertSame($runCount, SyncRun::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function configuration_absent_state_shows_setup_required_copy(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSee(__('sync_preview.states.setup_required'))
            ->assertSee(__('sync_preview.states.setup_permission_required'));
    }

    #[Test]
    public function configuration_absent_with_manage_setup_shows_setup_action(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermissions($workspace, [
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSee(__('sync_preview.states.setup_required'))
            ->assertSee('data-testid="sync-preview-setup-action"', false);
    }

    #[Test]
    public function account_unavailable_state_is_rendered(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace, ['is_enabled' => false]);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSee(__('sync_data_setup.adobe_products_export.account_unavailable'));
    }

    #[Test]
    public function explicit_start_creates_preview_run_through_admission(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('startPreview')
            ->assertSet('pageState', 'queued');

        $this->assertDatabaseHas('sync_runs', [
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'mode' => SyncRunMode::Preview->value,
            'semantic_operation' => SyncSemanticOperation::Export->value,
            'status' => SyncRunStatus::Queued->value,
        ]);
    }

    #[Test]
    public function failed_run_with_partial_items_renders_no_product_results(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $productA = $this->createProductWithVariant($workspace, 'Alpha', 'SKU-A', 'BrandA');
        $productB = $this->createProductWithVariant($workspace, 'Beta', 'SKU-B', 'BrandB');

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Failed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
            'completed_at' => now(),
        ]);

        SyncRunItem::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_run_id' => $run->id,
            'product_id' => $productA->id,
            'outcome' => SyncPreviewOutcome::Blocked,
            'findings' => [
                (new SyncPreviewFinding(SyncPreviewFindingCode::MissingName))->toArray(),
            ],
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('pageState', 'failed')
            ->assertSee(__('sync_preview.lifecycle.failed'))
            ->assertDontSee('Alpha')
            ->assertDontSee('SKU-A')
            ->assertDontSee('data-testid="sync-preview-worklist"', false);
    }

    #[Test]
    public function completed_run_renders_ready_warning_blocked_counts(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $run = $this->createCompletedRun($workspace, $configuration, $actor, [
            [SyncPreviewOutcome::Ready, 2],
            [SyncPreviewOutcome::Warning, 0],
            [SyncPreviewOutcome::Blocked, 1],
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('pageState', 'completed')
            ->assertSet('readyCount', 2)
            ->assertSet('warningCount', 0)
            ->assertSet('blockedCount', 1)
            ->assertSee(__('sync_preview.results.ready', ['count' => 2]))
            ->assertSee(__('sync_preview.results.warning', ['count' => 0]))
            ->assertSee(__('sync_preview.results.blocked', ['count' => 1]));
    }

    #[Test]
    public function worklist_search_matches_product_name_brand_and_any_current_variant_sku(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $multi = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'LEGACY-SKU-IGNORED',
            'name' => 'Cybex Balios S Lux',
            'brand' => 'CYBEX',
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $multi->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'BALIOS-BLK',
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $multi->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'BALIOS-BEI',
            'is_active' => true,
        ]);

        $other = $this->createProductWithVariant($workspace, 'Other Product', 'OTHER-SKU', 'OtherBrand');
        $run = $this->createCompletedRun($workspace, $configuration, $actor, [
            [SyncPreviewOutcome::Blocked, 1, $multi],
            [SyncPreviewOutcome::Ready, 1, $other],
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all')
            ->set('worklistSearch', 'BALIOS-BEI')
            ->assertSee('Cybex Balios S Lux')
            ->assertDontSee('Other Product')
            ->assertDontSee('LEGACY-SKU-IGNORED');

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all')
            ->set('worklistSearch', 'CYBEX')
            ->assertSee('Cybex Balios S Lux');
    }

    #[Test]
    public function single_variant_product_identity_uses_canonical_variant_sku_not_legacy_product_sku(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'LEGACY-PRODUCT-SKU',
            'name' => 'Single Variant Product',
            'brand' => 'BrandX',
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CANONICAL-SKU',
            'is_active' => true,
        ]);

        $this->createCompletedRun($workspace, $configuration, $actor, [
            [SyncPreviewOutcome::Blocked, 1, $product],
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all')
            ->assertSee(__('sync_preview.product_identity.single_sku', ['sku' => 'CANONICAL-SKU']))
            ->assertDontSee('LEGACY-PRODUCT-SKU');
    }

    #[Test]
    public function rendered_html_does_not_expose_raw_finding_codes_or_technical_identifiers(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $this->createProductWithVariant($workspace, 'Sensitive Product', 'SENS-SKU', 'SensBrand')->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => '',
            'is_active' => true,
        ]);

        $product = $variant->product;
        $binding = $this->productVariantBinding('sku');

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => [
                'field_mappings' => [
                    [
                        'field_binding_id' => $binding->id,
                        'external_field_key' => 'sku',
                        'option_mappings' => [],
                    ],
                ],
            ],
            'completed_at' => now(),
        ]);

        SyncRunItem::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_run_id' => $run->id,
            'product_id' => $product->id,
            'outcome' => SyncPreviewOutcome::Blocked,
            'findings' => [
                [
                    'code' => SyncPreviewFindingCode::MissingSku->value,
                    'subject' => (string) $variant->id,
                    'message_key' => SyncPreviewFindingCode::MissingSku->messageKey(),
                    'context' => ['field_binding_id' => $binding->id],
                ],
            ],
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all');

        $html = $component->html();
        $snapshot = $component->instance()->worklistRows;

        $this->assertStringNotContainsString('missing_sku', $html);
        $this->assertStringNotContainsString($binding->id, $html);
        $this->assertStringNotContainsString('field_binding_id', $html);
        $this->assertStringNotContainsString('configuration_snapshot', json_encode($snapshot));
        $component->assertSee(__('sync_preview.variant_context.without_sku'));
    }

    #[Test]
    public function all_seventeen_finding_codes_render_without_exception(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);
        $product = $this->createProductWithVariant($workspace, 'Finding Codes Product', 'FC-SKU', 'FCBrand');

        $findings = [];

        foreach (SyncPreviewFindingCode::cases() as $code) {
            $findings[] = [
                'code' => $code->value,
                'subject' => $code === SyncPreviewFindingCode::MissingRequiredFieldMapping ? 'name' : null,
                'message_key' => $code->messageKey(),
                'context' => $code === SyncPreviewFindingCode::MissingMappedVariantValue
                    ? ['field_binding_id' => $this->productVariantBinding('color')->id]
                    : [],
            ];
        }

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
            'completed_at' => now(),
        ]);

        SyncRunItem::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_run_id' => $run->id,
            'product_id' => $product->id,
            'outcome' => SyncPreviewOutcome::Blocked,
            'findings' => $findings,
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->set('worklistFilter', 'all')
            ->assertOk();
    }

    #[Test]
    public function same_livewire_component_rejects_after_run_sync_preview_revocation(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $membership = $this->grantExactWorkspacePermissions($workspace, User::factory()->create(['is_active' => true]), [
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        ]);
        $actor = $membership->user;

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertOk();

        $this->revokeAllWorkspaceRoles($membership);

        $component->call('refreshPresentation')->assertForbidden();
    }

    private function prepareReadyConfiguration(ConnectorAccount $account): SyncConfiguration
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

        $this->publishAuthoritativeSnapshot($account, ['name', 'sku']);

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

        return $configuration->refresh();
    }

    /**
     * @param  list<array{0: SyncPreviewOutcome, 1: int, 2?: Product}>  $buckets
     */
    private function createCompletedRun(
        Workspace $workspace,
        SyncConfiguration $configuration,
        User $actor,
        array $buckets,
    ): SyncRun {
        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
            'completed_at' => now(),
        ]);

        foreach ($buckets as $bucketIndex => $bucket) {
            [$outcome, $count, $product] = [$bucket[0], $bucket[1], $bucket[2] ?? null];

            for ($i = 0; $i < $count; $i++) {
                $itemProduct = ($product !== null && $count === 1)
                    ? $product
                    : $this->createProductWithVariant(
                        $workspace,
                        'Product '.$run->id.'-'.$bucketIndex.'-'.$i,
                        'SKU-'.$bucketIndex.'-'.$i,
                        'Brand',
                    );

                SyncRunItem::withoutWorkspaceScope()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'sync_run_id' => $run->id,
                    'product_id' => $itemProduct->id,
                    'outcome' => $outcome,
                    'findings' => $outcome === SyncPreviewOutcome::Ready
                        ? []
                        : [(new SyncPreviewFinding(SyncPreviewFindingCode::MissingName))->toArray()],
                ]);
            }
        }

        return $run;
    }

    private function createProductWithVariant(
        Workspace $workspace,
        string $name,
        string $sku,
        string $brand,
    ): Product {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'LEGACY-'.$sku,
            'name' => $name,
            'brand' => $brand,
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'is_active' => true,
        ]);

        return $product;
    }

    private function actorWithPermission(Workspace $workspace, string $permission): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions($workspace, $actor, [$permission]);

        return $actor;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actorWithPermissions(Workspace $workspace, array $permissions): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $this->grantExactWorkspacePermissions($workspace, $actor, $permissions);

        return $actor;
    }

    private function adobeAccount(?Workspace $workspace = null, array $overrides = []): ConnectorAccount
    {
        return $this->createConnectorAccount($workspace, $overrides);
    }
}
