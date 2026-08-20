<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncLiveOutcome;
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
use App\Support\Sync\ConnectorExecutionConfiguration;
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

class Stage3D2MerchantFirstLiveWorkSurfaceTest extends TestCase
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
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function preview_only_actor_sees_preview_section_but_not_live_section(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertOk()
            ->assertSet('previewSectionVisible', true)
            ->assertSet('liveSectionVisible', false)
            ->assertSee('data-testid="sync-preview-section"', false)
            ->assertDontSee('data-testid="sync-live-section"', false);
    }

    #[Test]
    public function live_only_actor_sees_live_section_but_not_preview_surface(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_LIVE);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertOk()
            ->assertSet('previewSectionVisible', false)
            ->assertSet('liveSectionVisible', true)
            ->assertDontSee('data-testid="sync-preview-section"', false)
            ->assertSee('data-testid="sync-live-section"', false)
            ->assertDontSee('data-testid="sync-preview-start"', false)
            ->assertDontSee('data-testid="sync-preview-worklist"', false);
    }

    #[Test]
    public function actor_with_both_permissions_sees_preview_and_live_sections(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermissions($workspace, [
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::RUN_SYNC_LIVE,
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertOk()
            ->assertSet('previewSectionVisible', true)
            ->assertSet('liveSectionVisible', true)
            ->assertSee('data-testid="sync-preview-section"', false)
            ->assertSee('data-testid="sync-live-section"', false);
    }

    #[Test]
    public function actor_with_neither_permission_cannot_reach_execution_page(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = User::factory()->create(['is_active' => true]);
        $this->makeWorkspaceMembership($workspace, $actor);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertForbidden();
    }

    #[Test]
    public function live_only_actor_discovers_landing_with_live_action_only(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_LIVE);

        Livewire::actingAs($actor)
            ->test(ListSyncDataSetup::class)
            ->assertOk()
            ->assertSee(__('sync_live.actions.open_live'))
            ->assertDontSee(__('sync_preview.actions.open_preview'))
            ->assertSee('data-testid="sync-data-setup-open-live-'.$account->id.'"', false);
    }

    #[Test]
    public function live_support_false_keeps_transfer_non_actionable(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_LIVE);
        $this->seedCompletedPreview($configuration, $actor);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('livePageState', 'support_not_enabled')
            ->assertSet('canStartLive', false)
            ->assertSee(__('sync_live.states.support_not_enabled'))
            ->assertDontSee('data-testid="sync-live-start"', false);

        $countBefore = SyncRun::withoutWorkspaceScope()->where('mode', SyncRunMode::Live)->count();

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('startLive');

        $this->assertSame($countBefore, SyncRun::withoutWorkspaceScope()->where('mode', SyncRunMode::Live)->count());
    }

    #[Test]
    public function live_only_actor_without_preview_evidence_sees_prerequisite_copy(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_LIVE);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('livePageState', 'preview_prerequisite_missing')
            ->assertSee(__('sync_live.states.preview_prerequisite_missing'))
            ->assertSee(__('sync_live.preview_prerequisite.missing'))
            ->assertDontSee('data-testid="sync-preview-worklist"', false);
    }

    #[Test]
    public function preview_only_actor_cannot_invoke_start_live(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->call('startLive')
            ->assertForbidden();
    }

    #[Test]
    public function completed_live_run_renders_merchant_outcome_counts(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_LIVE);
        $this->seedCompletedPreview($configuration, $actor);
        $this->createCompletedLiveRun($workspace, $configuration, $actor, [
            [SyncLiveOutcome::Synchronized, 2],
            [SyncLiveOutcome::NotApplied, 1],
            [SyncLiveOutcome::Partial, 0],
            [SyncLiveOutcome::Ambiguous, 1],
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('livePageState', 'completed')
            ->assertSet('liveSynchronizedCount', 2)
            ->assertSet('liveNotAppliedCount', 1)
            ->assertSet('livePartialCount', 0)
            ->assertSet('liveAmbiguousCount', 1)
            ->assertSee(__('sync_live.results.synchronized', ['count' => 2]))
            ->assertSee(__('sync_live.results.not_applied', ['count' => 1]))
            ->assertSee(__('sync_live.results.ambiguous', ['count' => 1]))
            ->assertSee(__('sync_live.results.ambiguous_attention', ['count' => 1]));
    }

    #[Test]
    public function running_live_run_renders_processed_product_count_without_percentage(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_LIVE);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Live,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Running,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
        ]);

        $product = $this->createProductWithVariant($workspace, 'Running Product', 'RUN-SKU', 'Brand');
        SyncRunItem::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'sync_run_id' => $run->id,
            'product_id' => $product->id,
            'outcome' => SyncLiveOutcome::Synchronized,
            'findings' => [],
        ]);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('livePageState', 'running')
            ->assertSet('liveProcessedProductCount', 1)
            ->assertSee(__('sync_live.lifecycle.running'))
            ->assertSee(__('sync_live.lifecycle.processed_products', ['count' => 1]))
            ->assertDontSee('%');
    }

    #[Test]
    public function rendered_live_section_avoids_forbidden_merchant_vocabulary(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_LIVE);
        $this->seedCompletedPreview($configuration, $actor);
        $this->createCompletedLiveRun($workspace, $configuration, $actor, [
            [SyncLiveOutcome::Ambiguous, 1],
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id]);

        $rendered = implode(' ', array_filter([
            (string) ($component->get('liveResultAttentionStatement') ?? ''),
            (string) ($component->get('liveLifecycleLabel') ?? ''),
            (string) ($component->get('liveCompletedAtLabel') ?? ''),
            __('sync_live.section.title'),
            __('sync_live.results.synchronized', ['count' => $component->get('liveSynchronizedCount') ?? 0]),
            __('sync_live.results.not_applied', ['count' => $component->get('liveNotAppliedCount') ?? 0]),
            __('sync_live.results.partial', ['count' => $component->get('livePartialCount') ?? 0]),
            __('sync_live.results.ambiguous', ['count' => $component->get('liveAmbiguousCount') ?? 0]),
        ]));

        foreach ([
            'externalrecordlink',
            'idempotency',
            'reconciliation',
            'auth profile',
            'endpoint path',
            'canonical hash',
            'schema source',
            'snapshot',
            'discovery run',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, mb_strtolower($rendered), "Forbidden vocabulary found: {$forbidden}");
        }
    }

    #[Test]
    public function same_livewire_component_rejects_after_run_sync_live_revocation(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $membership = $this->grantExactWorkspacePermissions($workspace, User::factory()->create(['is_active' => true]), [
            WorkspacePermissions::RUN_SYNC_LIVE,
        ]);
        $actor = $membership->user;

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertOk();

        $this->revokeAllWorkspaceRoles($membership);

        $component->call('refreshPresentation')->assertForbidden();
    }

    #[Test]
    public function actionable_live_start_is_available_only_when_support_is_enabled(): void
    {
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Preview],
            [SyncDataDomain::Products, SyncSemanticOperation::Export, SyncRunMode::Live],
        ]);

        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
        $configuration = $this->prepareReadyConfiguration($account);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_LIVE);
        $this->seedCompletedPreview($configuration, $actor);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportPreview::class, ['account' => $account->id])
            ->assertSet('livePageState', 'ready_to_transfer')
            ->assertSet('canStartLive', true)
            ->assertSee('data-testid="sync-live-start"', false);
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

    private function seedCompletedPreview(SyncConfiguration $configuration, User $actor): SyncRun
    {
        return SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $configuration->workspace_id,
            'sync_configuration_id' => $configuration->id,
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Preview,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  list<array{0: SyncLiveOutcome, 1: int}>  $buckets
     */
    private function createCompletedLiveRun(
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
            'mode' => SyncRunMode::Live,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'initiated_by_user_id' => $actor->id,
            'configuration_snapshot' => ['field_mappings' => []],
            'completed_at' => now(),
        ]);

        foreach ($buckets as $bucketIndex => $bucket) {
            [$outcome, $count] = $bucket;

            for ($i = 0; $i < $count; $i++) {
                $product = $this->createProductWithVariant(
                    $workspace,
                    'Live Product '.$bucketIndex.'-'.$i,
                    'LIVE-'.$bucketIndex.'-'.$i,
                    'Brand',
                );

                SyncRunItem::withoutWorkspaceScope()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'sync_run_id' => $run->id,
                    'product_id' => $product->id,
                    'outcome' => $outcome,
                    'findings' => [],
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
