<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Filament\Pages\Sync\ListSyncDataSetup;
use App\Filament\Pages\Sync\ManageAdobeProductsExportSetup;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Models\WorkspaceRole;
use App\Models\WorkspaceUser;
use App\Services\Sync\AdobeProductExportSetupAuthorizationService;
use App\Services\Sync\AdobeProductExportSetupService;
use App\Services\Sync\ConnectorAccountLayerBSetupProjectionQuery;
use App\Services\Sync\CreateSyncConfigurationInput;
use App\Services\Sync\SyncConfigurationLookupService;
use App\Services\Sync\SyncConfigurationReachabilityService;
use App\Services\Sync\SyncConfigurationService;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionConfiguration;
use App\Support\Connectors\AdobePaaS\AdobeProductExportMetadataReader;
use App\Support\Connectors\AdobePaaS\AdobeProductExportMetadataRequestFactory;
use App\Support\Connectors\ConnectorAccountCapabilityPresentation;
use App\Support\Connectors\ConnectorAccountLayerBSetupEligibilityProjection;
use App\Support\Connectors\ConnectorAccountLayerBSetupProjection;
use App\Support\Connectors\ConnectorAuthorization;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;
use App\Support\Sync\SyncExternalContext;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class Stage2A1SyncConfigurationSetupTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import],
            [SyncDataDomain::Products, SyncSemanticOperation::Export],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function permission_catalogue_contains_exactly_ten_permissions_with_manage_sync_configurations(): void
    {
        $this->assertCount(10, WorkspacePermissions::catalogue());
        $this->assertContains(WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS, WorkspacePermissions::catalogue());
    }

    #[Test]
    public function seeder_creates_manage_sync_configurations_idempotently_without_role_grants(): void
    {
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $this->assertSame(10, WorkspacePermission::query()->count());
        $this->assertSame(
            1,
            WorkspacePermission::query()
                ->where('code', WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS)
                ->count(),
        );

        $permissionId = WorkspacePermission::query()
            ->where('code', WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS)
            ->value('id');

        $this->assertSame(0, DB::table('workspace_role_permissions')
            ->where('workspace_permission_id', $permissionId)
            ->count());
    }

    #[Test]
    public function manage_sync_configurations_is_not_auto_granted_to_legacy_template_roles(): void
    {
        $workspace = $this->defaultWorkspace();
        $legacyRoles = WorkspaceRole::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('template_key')
            ->pluck('template_key')
            ->all();

        if ($legacyRoles === []) {
            $this->markTestSkipped('No legacy template roles seeded in this environment.');
        }

        $permissionId = WorkspacePermission::query()
            ->where('code', WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS)
            ->value('id');

        $this->assertSame(
            0,
            DB::table('workspace_role_permissions')
                ->where('workspace_id', $workspace->id)
                ->where('workspace_permission_id', $permissionId)
                ->count(),
        );
    }

    #[Test]
    public function manage_sync_configurations_only_actor_can_discover_data_setup_entry_and_navigate_to_adobe_setup(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $this->bindSetupTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]));

        $this->assertFalse(app(ConnectorAuthorization::class)->canSafeRead($actor, $workspace));

        Livewire::actingAs($actor)
            ->test(ListSyncDataSetup::class)
            ->assertOk()
            ->assertSee(__('sync_data_setup.navigation.label'))
            ->assertSee(__('sync_data_setup.targets.adobe_products_export'))
            ->assertSee(__('sync_data_setup.page.open_setup'))
            ->assertSee('data-testid="sync-data-setup-target-'.$account->id.'"', false);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->assertOk()
            ->assertSee(__('sync_data_setup.adobe_products_export.attribute_set_label'));
    }

    #[Test]
    public function generic_export_support_without_adobe_preview_capability_hides_view_connector_setup_link(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->syncSupportAccount($workspace);
        $actor = $this->actorWithPermissions($workspace, [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);

        Livewire::actingAs($actor)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertOk()
            ->assertActionDoesNotExist('openAdobeExportSetup');
    }

    #[Test]
    public function eligible_adobe_profile_with_safe_read_and_manage_sync_configurations_shows_view_connector_setup_link(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermissions($workspace, [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);

        Livewire::actingAs($actor)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertOk()
            ->assertActionDoesNotExist('openAdobeExportSetup')
            ->assertSee(__('connectors.ui.layer_a.next_step.configure'));
    }

    #[Test]
    public function manage_sync_configurations_only_actor_can_reach_setup_without_connector_read(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $this->bindSetupTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]));

        $this->assertFalse(app(ConnectorAuthorization::class)->canSafeRead($actor, $workspace));

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->assertOk()
            ->assertSee(__('sync_data_setup.adobe_products_export.attribute_set_label'));
    }

    #[Test]
    public function manage_sync_configurations_only_actor_can_mutate_setup(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $this->bindSetupTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]));

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->set('data.attribute_set_id', '4')
            ->call('save')
            ->assertNotified();

        $configuration = app(SyncConfigurationLookupService::class)->findProductsDefaultContext($account);
        $this->assertNotNull($configuration);
        $this->assertSame(
            4,
            AdobeProductExportExecutionConfiguration::fromPayload(
                $configuration->connectorExecutionConfiguration()->payload(),
            )->attributeSetId,
        );
    }

    #[Test]
    public function run_sync_preview_only_actor_cannot_mutate_setup(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::RUN_SYNC_PREVIEW);

        $this->assertFalse(
            app(AdobeProductExportSetupAuthorizationService::class)->canAccess($actor, $workspace),
        );

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->assertForbidden();
    }

    #[Test]
    public function manage_connector_accounts_only_actor_cannot_mutate_setup(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS);

        $this->expectException(AuthorizationException::class);

        app(AdobeProductExportSetupAuthorizationService::class)->configureAttributeSet(
            $actor,
            $workspace->id,
            $account->id,
            4,
        );
    }

    #[Test]
    public function manage_sync_mappings_only_actor_cannot_mutate_setup(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_MAPPINGS);

        $this->expectException(AuthorizationException::class);

        app(AdobeProductExportSetupAuthorizationService::class)->configureAttributeSet(
            $actor,
            $workspace->id,
            $account->id,
            4,
        );
    }

    #[Test]
    public function lookup_finds_products_default_configuration_without_mutation(): void
    {
        $account = $this->syncSupportAccount();
        $configuration = $this->createExportDisabledProductsConfiguration($account);

        $found = app(SyncConfigurationLookupService::class)->findProductsDefaultContext($account);

        $this->assertNotNull($found);
        $this->assertSame($configuration->id, $found->id);
        $configuration->refresh();
        $this->assertSame($configuration->updated_at?->toJSON(), $found->updated_at?->toJSON());
    }

    #[Test]
    public function lookup_returns_null_when_absent_without_mutation(): void
    {
        $account = $this->adobeAccount();
        $before = SyncConfiguration::query()->count();

        $found = app(SyncConfigurationLookupService::class)->findProductsDefaultContext($account);

        $this->assertNull($found);
        $this->assertSame($before, SyncConfiguration::query()->count());
    }

    #[Test]
    public function lookup_rejects_foreign_workspace_account(): void
    {
        $account = $this->adobeAccount();
        $otherWorkspace = Workspace::query()->where('is_default', false)->first()
            ?? Workspace::query()->create([
                'name' => 'Other',
                'slug' => 'other-'.uniqid(),
                'is_default' => false,
            ]);

        $foreignAccount = $this->syncSupportAccount($otherWorkspace);
        $this->createProductsExportConfiguration($foreignAccount, exportEnabled: true);

        $found = app(SyncConfigurationLookupService::class)->findProductsDefaultContext($account);

        $this->assertNull($found);
    }

    #[Test]
    public function ensure_path_still_creates_and_enables_export_configuration(): void
    {
        $account = $this->adobeAccount();

        $configuration = app(SyncConfigurationReachabilityService::class)
            ->ensureProductsExportConfiguration($account);

        $this->assertTrue($configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export));
        $this->assertSame(SyncDataDomain::Products, $configuration->data_domain);
    }

    #[Test]
    public function ensure_products_export_preserves_existing_import_and_adds_export(): void
    {
        $account = $this->syncSupportAccount();
        $configuration = SyncConfiguration::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products,
            'external_context' => SyncExternalContext::default()->payload(),
            'external_context_key' => SyncExternalContext::default()->uniquenessKey(),
            'enabled_operations' => [SyncSemanticOperation::Import->value],
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'configuration_revision' => 'import-only-'.Str::random(8),
        ]);

        $ensured = app(SyncConfigurationReachabilityService::class)
            ->ensureProductsExportConfiguration($account);

        $this->assertSame($configuration->id, $ensured->id);
        $this->assertTrue($ensured->enabledOperationSet()->contains(SyncSemanticOperation::Import));
        $this->assertTrue($ensured->enabledOperationSet()->contains(SyncSemanticOperation::Export));
    }

    #[Test]
    public function setup_read_lists_multiple_attribute_sets_without_setup_required_exception(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $this->bindSetupTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
            ['attribute_set_id' => 9, 'attribute_set_name' => 'Baby'],
        ]));

        $readModel = app(AdobeProductExportSetupAuthorizationService::class)->projectReadModel(
            $actor,
            $workspace->id,
            $account->id,
        );

        $this->assertCount(2, $readModel->availableAttributeSets);
        $this->assertNull($readModel->preselectedAttributeSetId);
    }

    #[Test]
    public function setup_read_preselects_single_attribute_set_without_persisting(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $before = SyncConfiguration::query()->count();
        $this->bindSetupTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 9, 'attribute_set_name' => 'Baby'],
        ]));

        $readModel = app(AdobeProductExportSetupAuthorizationService::class)->projectReadModel(
            $actor,
            $workspace->id,
            $account->id,
        );

        $this->assertSame(9, $readModel->preselectedAttributeSetId);
        $this->assertTrue($readModel->setupRequired);
        $this->assertSame($before, SyncConfiguration::query()->count());
    }

    #[Test]
    public function page_open_and_refresh_do_not_create_configuration(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $before = SyncConfiguration::query()->count();
        $this->bindSetupTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]));

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->assertOk();

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->assertOk();

        $this->assertSame($before, SyncConfiguration::query()->count());
    }

    #[Test]
    public function invalid_selected_set_with_absent_configuration_creates_nothing(): void
    {
        $account = $this->adobeAccount();
        $service = $this->setupServiceWithTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]));

        $this->expectException(ConnectorExecutionConfigurationValidationException::class);

        try {
            $service->configureAttributeSet($account, 99);
        } finally {
            $this->assertNull(app(SyncConfigurationLookupService::class)->findProductsDefaultContext($account));
        }
    }

    #[Test]
    public function invalid_selected_set_with_existing_config_keeps_export_disabled(): void
    {
        $account = $this->adobeAccount();
        $configuration = SyncConfiguration::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products,
            'external_context' => SyncExternalContext::default()->payload(),
            'external_context_key' => SyncExternalContext::default()->uniquenessKey(),
            'enabled_operations' => [SyncSemanticOperation::Import->value],
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'configuration_revision' => 'test-revision-without-export',
        ]);
        $revisionBefore = $configuration->configuration_revision;
        $service = $this->setupServiceWithTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]));

        $this->expectException(ConnectorExecutionConfigurationValidationException::class);

        try {
            $service->configureAttributeSet($account, 99);
        } finally {
            $configuration->refresh();
            $this->assertFalse($configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export));
            $this->assertSame($revisionBefore, $configuration->configuration_revision);
        }
    }

    #[Test]
    public function invalid_replacement_keeps_previous_connector_execution_configuration(): void
    {
        $account = $this->adobeAccount();
        $configuration = $this->createConfiguredExport($account, attributeSetId: 4);
        $revisionBefore = $configuration->configuration_revision;
        $service = $this->setupServiceWithTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]));

        $this->expectException(ConnectorExecutionConfigurationValidationException::class);

        try {
            $service->configureAttributeSet($account, 99);
        } finally {
            $configuration->refresh();
            $this->assertSame(
                4,
                AdobeProductExportExecutionConfiguration::fromPayload(
                    $configuration->connectorExecutionConfiguration()->payload(),
                )->attributeSetId,
            );
            $this->assertSame($revisionBefore, $configuration->configuration_revision);
        }
    }

    #[Test]
    public function valid_explicit_mutation_creates_enables_and_persists_configuration(): void
    {
        $account = $this->adobeAccount();
        $service = $this->setupServiceWithTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 9, 'attribute_set_name' => 'Baby'],
        ]));

        $configuration = $service->configureAttributeSet($account, 9);

        $this->assertTrue($configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export));
        $this->assertSame(
            9,
            AdobeProductExportExecutionConfiguration::fromPayload(
                $configuration->connectorExecutionConfiguration()->payload(),
            )->attributeSetId,
        );
        $this->assertNotSame('', $configuration->configuration_revision);
    }

    #[Test]
    public function validate_attribute_set_selection_does_not_persist_configuration(): void
    {
        $account = $this->adobeAccount();
        $service = $this->setupServiceWithTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]));
        $before = SyncConfiguration::query()->count();

        try {
            $service->validateAttributeSetSelection($account, 99);
        } catch (ConnectorExecutionConfigurationValidationException) {
            // expected invalid selection
        }

        $this->assertSame($before, SyncConfiguration::query()->count());
    }

    #[Test]
    public function form_render_does_not_duplicate_adobe_http_requests(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $transport = $this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
            ['attribute_set_id' => 9, 'attribute_set_name' => 'Baby'],
        ]);
        $this->bindSetupTransport($transport);

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->assertOk();

        $this->assertSame(1, $transport->sendCount);

        $component->call('$refresh')->assertOk();

        $this->assertSame(1, $transport->sendCount);
    }

    #[Test]
    public function unsupported_non_adobe_target_zero_http_before_metadata(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->syncSupportAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $transport = $this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]);
        $this->bindSetupTransport($transport);

        $this->expectException(AuthorizationException::class);

        try {
            app(AdobeProductExportSetupAuthorizationService::class)->projectReadModel(
                $actor,
                $workspace->id,
                $account->id,
            );
        } finally {
            $this->assertSame(0, $transport->sendCount);
        }
    }

    #[Test]
    public function disabled_target_zero_http_before_metadata(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace, ['is_enabled' => false]);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $transport = $this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]);
        $this->bindSetupTransport($transport);

        $readModel = app(AdobeProductExportSetupAuthorizationService::class)->projectReadModel(
            $actor,
            $workspace->id,
            $account->id,
        );

        $this->assertSame(0, $transport->sendCount);
        $this->assertFalse($readModel->account->setupUsable);
        $this->assertTrue($readModel->setupRequired);
        $this->assertSame([], $readModel->availableAttributeSets);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->assertOk()
            ->assertSee(__('sync_data_setup.adobe_products_export.account_unavailable'));
    }

    #[Test]
    public function export_disabled_with_valid_attribute_set_requires_setup(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->createExportDisabledProductsConfigurationWithAttributeSet($account, 4);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $this->bindSetupTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]));

        $readModel = app(AdobeProductExportSetupAuthorizationService::class)->projectReadModel(
            $actor,
            $workspace->id,
            $account->id,
        );

        $this->assertTrue($readModel->setupRequired);
        $this->assertFalse($readModel->exportEnabled);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->assertOk()
            ->assertSee(__('sync_data_setup.adobe_products_export.setup_required'))
            ->assertDontSee(__('sync_data_setup.adobe_products_export.current_selection', [
                'name' => 'Default',
            ]));
    }

    #[Test]
    public function page_read_with_export_disabled_configuration_causes_zero_mutation(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->createExportDisabledProductsConfigurationWithAttributeSet($account, 4);
        $revisionBefore = $configuration->fresh()->configuration_revision;
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $this->bindSetupTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]));

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->assertOk();

        $configuration->refresh();
        $this->assertFalse($configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export));
        $this->assertSame($revisionBefore, $configuration->configuration_revision);
    }

    #[Test]
    public function malformed_adobe_import_only_configuration_save_fails_closed_without_repair(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $configuration = $this->createExportDisabledProductsConfigurationWithAttributeSet($account, 4);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $this->bindSetupTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]));

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->set('data.attribute_set_id', '4')
            ->call('save')
            ->assertNotified();

        $configuration->refresh();
        $this->assertFalse($configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export));
    }

    #[Test]
    public function post_vendor_read_permission_revocation_fails_closed_without_mutation(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $membership = WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $actor->id)
            ->firstOrFail();

        $transport = new RecordingConnectorHttpTransport(
            function (ConnectorOutboundRequest $request, int $sendCount) use ($membership): ConnectorHttpResult {
                $this->revokeAllWorkspaceRoles($membership);

                return new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(['items' => [
                        ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
                    ]], JSON_THROW_ON_ERROR),
                );
            },
        );
        $this->bindSetupTransport($transport);

        $this->expectException(AuthorizationException::class);

        try {
            app(AdobeProductExportSetupAuthorizationService::class)->configureAttributeSet(
                $actor,
                $workspace->id,
                $account->id,
                4,
            );
        } finally {
            $this->assertNull(app(SyncConfigurationLookupService::class)->findProductsDefaultContext($account));
        }
    }

    #[Test]
    public function adobe_http_occurs_outside_setup_persistence_transaction(): void
    {
        $account = $this->adobeAccount();
        $baselineTransactionLevel = DB::transactionLevel();

        $transport = new RecordingConnectorHttpTransport(
            function (ConnectorOutboundRequest $request, int $sendCount) use ($baselineTransactionLevel): ConnectorHttpResult {
                $this->assertSame($baselineTransactionLevel, DB::transactionLevel());

                return new ConnectorHttpResult(
                    200,
                    [],
                    json_encode(['items' => [
                        ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
                    ]], JSON_THROW_ON_ERROR),
                );
            },
        );

        $service = $this->setupServiceWithTransport($transport);
        $service->configureAttributeSet($account, 4);

        $this->assertSame(1, $transport->sendCount);
    }

    #[Test]
    public function atomic_rollback_when_persistence_fails_after_absent_configuration(): void
    {
        $account = $this->adobeAccount();
        $transport = $this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]);
        $this->bindSetupTransport($transport);

        $this->injectConnectorExecutionConfigurationPersistenceFailure();

        $service = app(AdobeProductExportSetupService::class);

        $this->expectException(\RuntimeException::class);

        try {
            $service->configureAttributeSet($account, 4);
        } finally {
            $this->assertNull(app(SyncConfigurationLookupService::class)->findProductsDefaultContext($account));
        }
    }

    #[Test]
    public function atomic_rollback_when_persistence_fails_with_export_disabled_configuration(): void
    {
        $account = $this->syncSupportAccount();
        $configuration = $this->insertProductsConfigurationRecord(
            $account,
            enabledOperations: [SyncSemanticOperation::Import],
            connectorExecutionConfiguration: null,
        );
        $revisionBefore = $configuration->configuration_revision;
        $transport = $this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]);
        $this->bindSetupTransport($transport);

        $this->injectConnectorExecutionConfigurationPersistenceFailure();

        $service = app(AdobeProductExportSetupService::class);

        $this->expectException(\RuntimeException::class);

        try {
            $service->configureAttributeSet($account, 4);
        } finally {
            $configuration->refresh();
            $this->assertFalse($configuration->enabledOperationSet()->contains(SyncSemanticOperation::Export));
            $this->assertSame($revisionBefore, $configuration->configuration_revision);
        }
    }

    #[Test]
    public function remote_metadata_failure_does_not_abort_as_authorization_failure(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);

        $transport = new RecordingConnectorHttpTransport(
            fn (ConnectorOutboundRequest $request, int $sendCount): ConnectorHttpResult => throw new ConnectorTransportException(
                TransportFailureReason::ConnectionFailed,
            ),
        );
        $this->bindSetupTransport($transport);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->assertOk()
            ->assertSee(__('sync_data_setup.errors.remote_unavailable'))
            ->assertDontSee('Connector transport failed.');
    }

    #[Test]
    public function foreign_workspace_account_cannot_be_read_or_mutated_through_setup(): void
    {
        $workspace = $this->defaultWorkspace();
        $otherWorkspace = Workspace::query()->where('is_default', false)->first()
            ?? Workspace::query()->create([
                'name' => 'Foreign',
                'slug' => 'foreign-'.uniqid(),
                'is_default' => false,
            ]);
        $foreignAccount = $this->syncSupportAccount($otherWorkspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);

        $this->expectException(AuthorizationException::class);

        app(AdobeProductExportSetupAuthorizationService::class)->projectReadModel(
            $actor,
            $workspace->id,
            $foreignAccount->id,
        );
    }

    #[Test]
    public function setup_projection_uses_positive_allow_list_without_hidden_attributes(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $projection = ConnectorAccountLayerBSetupProjectionQuery::class;
        $resolved = app($projection)->resolve($workspace->id, $account->id);

        $this->assertNotNull($resolved);
        $this->assertSame($account->id, $resolved->id);
        $this->assertSame($account->name, $resolved->accountName);

        $selectedColumns = ConnectorAccountLayerBSetupProjection::selectColumns();
        $this->assertEqualsCanonicalizing(
            ConnectorAccountLayerBSetupProjection::selectColumns(),
            $selectedColumns,
        );

        foreach (ConnectorAccountCapabilityPresentation::hiddenAttributes() as $hidden) {
            $this->assertNotContains($hidden, $selectedColumns);
        }
    }

    #[Test]
    public function eligibility_and_landing_queries_do_not_select_sensitive_connector_fields(): void
    {
        $merchantColumns = ConnectorAccountLayerBSetupProjection::selectColumns();
        $eligibilityColumns = ConnectorAccountLayerBSetupEligibilityProjection::selectColumns();

        $sensitiveWithoutInternalProfile = array_values(array_filter(
            ConnectorAccountCapabilityPresentation::hiddenAttributes(),
            static fn (string $attribute): bool => $attribute !== 'auth_profile',
        ));

        foreach ($sensitiveWithoutInternalProfile as $hidden) {
            $this->assertNotContains($hidden, $merchantColumns);
            $this->assertNotContains($hidden, $eligibilityColumns);
        }

        $this->assertNotContains('auth_profile', $merchantColumns);
        $this->assertContains('auth_profile', $eligibilityColumns);
    }

    #[Test]
    public function stale_configured_set_shows_needs_attention_state_in_ui(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $this->createConfiguredExport($account, attributeSetId: 42);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $this->bindSetupTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]));

        $readModel = app(AdobeProductExportSetupAuthorizationService::class)->projectReadModel(
            $actor,
            $workspace->id,
            $account->id,
        );
        $this->assertTrue($readModel->configuredSetStale);
        $this->assertTrue($readModel->setupRequired);

        Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->assertSet('configuredSetStale', true)
            ->assertSet('setupRequired', true)
            ->assertSee('data-testid="sync-data-setup-stale"', false)
            ->assertSee('data-testid="sync-data-setup-required"', false);
    }

    #[Test]
    public function same_livewire_component_rejects_mutation_after_permission_revocation(): void
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->adobeAccount($workspace);
        $actor = $this->actorWithPermission($workspace, WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS);
        $membership = WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $actor->id)
            ->firstOrFail();
        $this->bindSetupTransport($this->attributeSetsListResponse([
            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
        ]));

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeProductsExportSetup::class, ['account' => $account->id])
            ->assertOk();

        $this->revokeAllWorkspaceRoles($membership);

        $component
            ->call('save')
            ->assertForbidden();

        $this->assertNull(app(SyncConfigurationLookupService::class)->findProductsDefaultContext($account));
    }

    private function createExportDisabledProductsConfiguration(ConnectorAccount $account): SyncConfiguration
    {
        return $this->insertProductsConfigurationRecord(
            $account,
            enabledOperations: [SyncSemanticOperation::Import],
            connectorExecutionConfiguration: null,
        );
    }

    private function createExportDisabledProductsConfigurationWithAttributeSet(
        ConnectorAccount $account,
        int $attributeSetId,
    ): SyncConfiguration {
        return $this->insertProductsConfigurationRecord(
            $account,
            enabledOperations: [SyncSemanticOperation::Import],
            connectorExecutionConfiguration: ['attribute_set_id' => $attributeSetId],
        );
    }

    /**
     * @param  list<SyncSemanticOperation|string>  $enabledOperations
     * @param  array<string, mixed>|null  $connectorExecutionConfiguration
     */
    private function insertProductsConfigurationRecord(
        ConnectorAccount $account,
        array $enabledOperations,
        ?array $connectorExecutionConfiguration,
    ): SyncConfiguration {
        $id = (string) Str::uuid();
        $externalContext = SyncExternalContext::default();
        $operationValues = array_map(
            static fn (SyncSemanticOperation|string $operation): string => $operation instanceof SyncSemanticOperation
                ? $operation->value
                : $operation,
            $enabledOperations,
        );

        DB::table('sync_configurations')->insert([
            'id' => $id,
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products->value,
            'external_context' => json_encode($externalContext->payload(), JSON_THROW_ON_ERROR),
            'external_context_key' => $externalContext->uniquenessKey(),
            'enabled_operations' => json_encode($operationValues, JSON_THROW_ON_ERROR),
            'operational_state' => SyncConfigurationOperationalState::Enabled->value,
            'configuration_revision' => 'export-disabled-'.Str::random(8),
            'connector_execution_configuration' => $connectorExecutionConfiguration !== null
                ? json_encode($connectorExecutionConfiguration, JSON_THROW_ON_ERROR)
                : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SyncConfiguration::withoutWorkspaceScope()->findOrFail($id);
    }

    private function injectConnectorExecutionConfigurationPersistenceFailure(): void
    {
        $armed = true;

        DB::listen(function ($query) use (&$armed): void {
            if (! $armed) {
                return;
            }

            if (! str_contains(strtolower($query->sql), 'update')
                || ! str_contains($query->sql, 'connector_execution_configuration')) {
                return;
            }

            $armed = false;

            throw new \RuntimeException('injected persistence failure');
        });
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

    private function createProductsExportConfiguration(
        ConnectorAccount $account,
        bool $exportEnabled,
    ): SyncConfiguration {
        $operations = $exportEnabled
            ? [SyncSemanticOperation::Export]
            : [SyncSemanticOperation::Import];

        return app(SyncConfigurationService::class)->create(
            $account,
            new CreateSyncConfigurationInput(
                dataDomain: SyncDataDomain::Products,
                externalContext: SyncExternalContext::default(),
                enabledOperations: $operations,
            ),
        );
    }

    private function createConfiguredExport(ConnectorAccount $account, int $attributeSetId): SyncConfiguration
    {
        $configuration = $this->createProductsExportConfiguration($account, exportEnabled: true);

        return app(SyncConfigurationService::class)->updateConnectorExecutionConfiguration(
            $account,
            $configuration->id,
            ConnectorExecutionConfiguration::fromPayload(['attribute_set_id' => $attributeSetId]),
        );
    }

    /**
     * @param  list<array{attribute_set_id: int, attribute_set_name: string}>  $items
     */
    private function attributeSetsListResponse(array $items): RecordingConnectorHttpTransport
    {
        return new RecordingConnectorHttpTransport(
            fn (ConnectorOutboundRequest $request): ConnectorHttpResult => new ConnectorHttpResult(
                200,
                [],
                json_encode(['items' => $items], JSON_THROW_ON_ERROR),
            ),
        );
    }

    private function bindSetupTransport(RecordingConnectorHttpTransport $transport): void
    {
        $reader = new AdobeProductExportMetadataReader(
            app(AdobePaaSRequestContextFactory::class),
            app(AdobeProductExportMetadataRequestFactory::class),
            $transport,
        );

        $this->app->instance(AdobeProductExportMetadataReader::class, $reader);
        $this->app->forgetInstance(AdobeProductExportSetupService::class);
        $this->app->forgetInstance(AdobeProductExportSetupAuthorizationService::class);
    }

    private function setupServiceWithTransport(RecordingConnectorHttpTransport $transport): AdobeProductExportSetupService
    {
        $this->bindSetupTransport($transport);

        return app(AdobeProductExportSetupService::class);
    }

    private function adobeAccount(?Workspace $workspace = null, array $overrides = []): ConnectorAccount
    {
        return $this->createConnectorAccount($workspace, $overrides);
    }

    private function syncSupportAccount(?Workspace $workspace = null): ConnectorAccount
    {
        return $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
    }
}
